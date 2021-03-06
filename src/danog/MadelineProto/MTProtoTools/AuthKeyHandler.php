<?php
/*
Copyright 2016-2017 Daniil Gentili
(https://daniil.it)
This file is part of MadelineProto.
MadelineProto is free software: you can redistribute it and/or modify it under the terms of the GNU Affero General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
MadelineProto is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
See the GNU Affero General Public License for more details.
You should have received a copy of the GNU General Public License along with MadelineProto.
If not, see <http://www.gnu.org/licenses/>.
*/

namespace danog\MadelineProto\MTProtoTools;

/**
 * Manages the creation of the authorization key.
 *
 * https://core.telegram.org/mtproto/auth_key
 * https://core.telegram.org/mtproto/samples-auth_key
 */
trait AuthKeyHandler
{
    public $dh_config = ['version' => 0];

    public function create_auth_key($expires_in = -1)
    {
        for ($retry_id_total = 1; $retry_id_total <= $this->settings['max_tries']['authorization']; $retry_id_total++) {
            try {
                \danog\MadelineProto\Logger::log(['Requesting pq'], \danog\MadelineProto\Logger::VERBOSE);

                /**
                 * ***********************************************************************
                 * Make pq request, DH exchange initiation.
                 *
                 * @method req_pq
                 *
                 * @param [
                 * 		int128 		$nonce 							: The value of nonce is selected randomly by the client (random number) and identifies the client within this communication
                 * ]
                 *
                 * @return ResPQ [
                 *               int128 		$nonce 							: The value of nonce is selected randomly by the server
                 *               int128 		$server_nonce 					: The value of server_nonce is selected randomly by the server
                 *               string 		$pq 							: This is a representation of a natural number (in binary big endian format). This number is the product of two different odd prime numbers
                 *               Vector long $server_public_key_fingerprints : This is a list of public RSA key fingerprints
                 *               ]
                 */
                $nonce = $this->random(16);
                $ResPQ = $this->method_call('req_pq',
                    [
                        'nonce' => $nonce,
                    ]
                );

                /*
                * ***********************************************************************
                * Check if the client's nonce and the server's nonce are the same
                */
                if ($ResPQ['nonce'] !== $nonce) {
                    throw new \danog\MadelineProto\SecurityException('wrong nonce');
                }

                /*
                * ***********************************************************************
                * Find our key in the server_public_key_fingerprints vector
                */
                if (!isset($this->key->keydata['fp'])) {
                    $this->key = new \danog\MadelineProto\RSA($this->settings['authorization']['rsa_key']);
                }
                foreach ($ResPQ['server_public_key_fingerprints'] as $curfp) {
                    if ($this->key->keydata['fp'] === $curfp) {
                        $public_key_fingerprint = $curfp;
                        break;
                    }
                }

                if (!isset($public_key_fingerprint)) {
                    throw new \danog\MadelineProto\SecurityException("Couldn't find our key in the server_public_key_fingerprints vector.");
                }

                $pq_bytes = $ResPQ['pq'];
                $server_nonce = $ResPQ['server_nonce'];

                /*
                * ***********************************************************************
                * Compute p and q
                */
                $pq = \danog\PHP\Struct::unpack('>Q', $pq_bytes)[0];
                $p = \danog\PrimeModule::auto_single($pq);
                $q = $pq / $p;
                if ($p > $q) {
                    list($p, $q) = [$q, $p];
                }

                if ($pq !== $p * $q) {
                    throw new \danog\MadelineProto\SecurityException("couldn't compute p and q.");
                }

                \danog\MadelineProto\Logger::log(['Factorization '.$pq.' = '.$p.' * '.$q], \danog\MadelineProto\Logger::VERBOSE);

                /*
                * ***********************************************************************
                * Serialize object for req_DH_params
                */
                $p_bytes = \danog\PHP\Struct::pack('>I', $p);
                $q_bytes = \danog\PHP\Struct::pack('>I', $q);

                $new_nonce = $this->random(32);

                $data_unserialized = [
                    'pq'              => $pq_bytes,
                    'p'               => $p_bytes,
                    'q'               => $q_bytes,
                    'nonce'           => $nonce,
                    'server_nonce'    => $server_nonce,
                    'new_nonce'       => $new_nonce,
                    'expires_in'      => $expires_in,
                ];
                $p_q_inner_data = $this->serialize_object(['type' => 'p_q_inner_data'.(($expires_in < 0) ? '' : '_temp')], $data_unserialized);

                /*
                * ***********************************************************************
                * Encrypt serialized object
                */
                $sha_digest = sha1($p_q_inner_data, true);
                $random_bytes = $this->random(255 - strlen($p_q_inner_data) - strlen($sha_digest));
                $to_encrypt = $sha_digest.$p_q_inner_data.$random_bytes;
                $encrypted_data = $this->key->encrypt($to_encrypt);

                \danog\MadelineProto\Logger::log(['Starting Diffie Hellman key exchange'], \danog\MadelineProto\Logger::VERBOSE);
                /*
                * ***********************************************************************
                * Starting Diffie Hellman key exchange, Server authentication
                * @method req_DH_params
                * @param [
                * 		int128 		$nonce 							: The value of nonce is selected randomly by the client (random number) and identifies the client within this communication
                * 		int128		$server_nonce					: The value of server_nonce is selected randomly by the server
                * 		string		$p								: The value of BigInteger
                * 		string		$q								: The value of BigInteger
                * 		long		$public_key_fingerprint			: This is our key in the server_public_key_fingerprints vector
                * 		string		$encrypted_data
                * ]
                * @return Server_DH_Params [
                * 		int128 		$nonce 						: The value of nonce is selected randomly by the server
                * 		int128 		$server_nonce 					: The value of server_nonce is selected randomly by the server
                * 		string 		$new_nonce_hash					: Return this value if server responds with server_DH_params_fail
                * 		string 		$encrypted_answer				: Return this value if server responds with server_DH_params_ok
                * ]
                */
                //
                $server_dh_params = $this->method_call('req_DH_params',
                    [
                        'nonce'                  => $nonce,
                        'server_nonce'           => $server_nonce,
                        'p'                      => $p_bytes,
                        'q'                      => $q_bytes,
                        'public_key_fingerprint' => $public_key_fingerprint,
                        'encrypted_data'         => $encrypted_data,
                    ]
                );

                /*
                * ***********************************************************************
                * Check if the client's nonce and the server's nonce are the same
                */
                if ($nonce != $server_dh_params['nonce']) {
                    throw new \danog\MadelineProto\SecurityException('wrong nonce.');
                }

                /*
                * ***********************************************************************
                * Check if server_nonce and new server_nonce are the same
                */
                if ($server_nonce != $server_dh_params['server_nonce']) {
                    throw new \danog\MadelineProto\SecurityException('wrong server nonce.');
                }

                /*
                * ***********************************************************************
                * Check valid new nonce hash if return from server
                * new nonce hash return in server_DH_params_fail
                */
                if (isset($server_dh_params['new_nonce_hash']) && substr(sha1($new_nonce), -32) != $server_dh_params['new_nonce_hash']) {
                    throw new \danog\MadelineProto\SecurityException('wrong new nonce hash.');
                }

                /*
                * ***********************************************************************
                * Get key, iv and decrypt answer
                */
                $encrypted_answer = $server_dh_params['encrypted_answer'];

                $tmp_aes_key = sha1($new_nonce.$server_nonce, true).substr(sha1($server_nonce.$new_nonce, true), 0, 12);
                $tmp_aes_iv = substr(sha1($server_nonce.$new_nonce, true), 12, 8).sha1($new_nonce.$new_nonce, true).substr($new_nonce, 0, 4);
                $answer_with_hash = $this->ige_decrypt($encrypted_answer, $tmp_aes_key, $tmp_aes_iv);

                /*
                * ***********************************************************************
                * Separate answer and hash
                */
                $answer_hash = substr($answer_with_hash, 0, 20);
                $answer = substr($answer_with_hash, 20);

                /*
                * ***********************************************************************
                * Deserialize answer
                * @return Server_DH_inner_data [
                * 		int128 		$nonce 							: The value of nonce is selected randomly by the client (random number) and identifies the client within this communication
                * 		int128		$server_nonce					: The value of server_nonce is selected randomly by the server
                * 		int			$g
                * 		string		$dh_prime
                * 		string		$g_a
                * 		int			$server_time
                * ]
                */
                $server_DH_inner_data = $this->deserialize($answer);

                /*
                * ***********************************************************************
                * Do some checks
                */
                $server_DH_inner_data_length = $this->get_length(new \danog\MadelineProto\Stream($answer));
                if (sha1(substr($answer, 0, $server_DH_inner_data_length), true) != $answer_hash) {
                    throw new \danog\MadelineProto\SecurityException('answer_hash mismatch.');
                }

                if ($nonce != $server_DH_inner_data['nonce']) {
                    throw new \danog\MadelineProto\SecurityException('wrong nonce');
                }

                if ($server_nonce != $server_DH_inner_data['server_nonce']) {
                    throw new \danog\MadelineProto\SecurityException('wrong server nonce');
                }

                $g = new \phpseclib\Math\BigInteger($server_DH_inner_data['g']);
                $g_a = new \phpseclib\Math\BigInteger($server_DH_inner_data['g_a'], 256);
                $dh_prime = new \phpseclib\Math\BigInteger($server_DH_inner_data['dh_prime'], 256);

                /*
                * ***********************************************************************
                * Time delta
                */
                $server_time = $server_DH_inner_data['server_time'];
                $this->datacenter->time_delta = $server_time - time();

                \danog\MadelineProto\Logger::log([sprintf('Server-client time delta = %.1f s', $this->datacenter->time_delta)], \danog\MadelineProto\Logger::VERBOSE);

                $this->check_p_g($dh_prime, $g);
                $this->check_G($g_a, $dh_prime);

                for ($retry_id = 0; $retry_id <= $this->settings['max_tries']['authorization']; $retry_id++) {
                    \danog\MadelineProto\Logger::log(['Generating b...'], \danog\MadelineProto\Logger::VERBOSE);
                    $b = new \phpseclib\Math\BigInteger($this->random(256), 256);
                    \danog\MadelineProto\Logger::log(['Generating g_b...'], \danog\MadelineProto\Logger::VERBOSE);
                    $g_b = $g->powMod($b, $dh_prime);
                    $this->check_G($g_b, $dh_prime);

                    /*
                    * ***********************************************************************
                    * Check validity of g_b
                    * 1 < g_b < dh_prime - 1
                    */
                    \danog\MadelineProto\Logger::log(['Executing g_b check...'], \danog\MadelineProto\Logger::VERBOSE);
                    if ($g_b->compare($this->one) <= 0 // 1 < g_b or g_b > 1 or ! g_b <= 1
                        || $g_b->compare($dh_prime->subtract($this->one)) >= 0 // g_b < dh_prime - 1 or ! g_b >= dh_prime - 1
                    ) {
                        throw new \danog\MadelineProto\SecurityException('g_b is invalid (1 < g_b < dh_prime - 1 is false).');
                    }

                    \danog\MadelineProto\Logger::log(['Preparing client_DH_inner_data...'], \danog\MadelineProto\Logger::VERBOSE);

                    $g_b_str = $g_b->toBytes();

                    /*
                    * ***********************************************************************
                    * serialize client_DH_inner_data
                    * @method client_DH_inner_data
                    * @param Server_DH_inner_data [
                    * 		int128 		$nonce 							: The value of nonce is selected randomly by the client (random number) and identifies the client within this communication
                    * 		int128		$server_nonce					: The value of server_nonce is selected randomly by the server
                    * 		long		$retry_id						: First attempt
                    * 		string		$g_b							: g^b mod dh_prime
                    * ]
                    */
                    $data = $this->serialize_object(
                        ['type' => 'client_DH_inner_data'],
                        [
                            'nonce'           => $nonce,
                            'server_nonce'    => $server_nonce,
                            'retry_id'        => $retry_id,
                            'g_b'             => $g_b_str,
                        ]
                    );

                    /*
                    * ***********************************************************************
                    * encrypt client_DH_inner_data
                    */
                    $data_with_sha = sha1($data, true).$data;
                    $data_with_sha_padded = $data_with_sha.$this->random($this->posmod(-strlen($data_with_sha), 16));
                    $encrypted_data = $this->ige_encrypt($data_with_sha_padded, $tmp_aes_key, $tmp_aes_iv);

                    \danog\MadelineProto\Logger::log(['Executing set_client_DH_params...'], \danog\MadelineProto\Logger::VERBOSE);
                    /*
                    * ***********************************************************************
                    * Send set_client_DH_params query
                    * @method set_client_DH_params
                    * @param Server_DH_inner_data [
                    * 		int128 		$nonce 							: The value of nonce is selected randomly by the client (random number) and identifies the client within this communication
                    * 		int128		$server_nonce					: The value of server_nonce is selected randomly by the server
                    * 		string		$encrypted_data
                    * ]
                    * @return Set_client_DH_params_answer [
                    * 		string 		$_ 								: This value is dh_gen_ok, dh_gen_retry OR dh_gen_fail
                    * 		int128 		$server_nonce 					: The value of server_nonce is selected randomly by the server
                    * 		int128 		$new_nonce_hash1				: Return this value if server responds with dh_gen_ok
                    * 		int128 		$new_nonce_hash2				: Return this value if server responds with dh_gen_retry
                    * 		int128 		$new_nonce_hash2				: Return this value if server responds with dh_gen_fail
                    * ]
                    */
                    $Set_client_DH_params_answer = $this->method_call(
                        'set_client_DH_params',
                        [
                            'nonce'               => $nonce,
                            'server_nonce'        => $server_nonce,
                            'encrypted_data'      => $encrypted_data,
                        ]
                    );

                    /*
                    * ***********************************************************************
                    * Generate auth_key
                    */
                    \danog\MadelineProto\Logger::log(['Generating authorization key...'], \danog\MadelineProto\Logger::VERBOSE);
                    $auth_key = $g_a->powMod($b, $dh_prime);
                    $auth_key_str = $auth_key->toBytes();
                    $auth_key_sha = sha1($auth_key_str, true);
                    $auth_key_aux_hash = substr($auth_key_sha, 0, 8);
                    $new_nonce_hash1 = substr(sha1($new_nonce.chr(1).$auth_key_aux_hash, true), -16);
                    $new_nonce_hash2 = substr(sha1($new_nonce.chr(2).$auth_key_aux_hash, true), -16);
                    $new_nonce_hash3 = substr(sha1($new_nonce.chr(3).$auth_key_aux_hash, true), -16);

                    /*
                    * ***********************************************************************
                    * Check if the client's nonce and the server's nonce are the same
                    */
                    if ($Set_client_DH_params_answer['nonce'] != $nonce) {
                        throw new \danog\MadelineProto\SecurityException('wrong nonce.');
                    }

                    /*
                    * ***********************************************************************
                    * Check if server_nonce and new server_nonce are the same
                    */
                    if ($Set_client_DH_params_answer['server_nonce'] != $server_nonce) {
                        throw new \danog\MadelineProto\SecurityException('wrong server nonce');
                    }

                    /*
                    * ***********************************************************************
                    * Check Set_client_DH_params_answer type
                    */
                    switch ($Set_client_DH_params_answer['_']) {
                        case 'dh_gen_ok':
                            if ($Set_client_DH_params_answer['new_nonce_hash1'] != $new_nonce_hash1) {
                                throw new \danog\MadelineProto\SecurityException('wrong new_nonce_hash1');
                            }

                            \danog\MadelineProto\Logger::log(['Diffie Hellman key exchange processed successfully!'], \danog\MadelineProto\Logger::VERBOSE);

                            $res_authorization['server_salt'] = \danog\PHP\Struct::unpack('<q', substr($new_nonce, 0, 8 - 0) ^ substr($server_nonce, 0, 8 - 0))[0];
                            $res_authorization['auth_key'] = $auth_key_str;
                            $res_authorization['id'] = substr($auth_key_sha, -8);

                            if ($expires_in >= 0) { //check if permanent authorization
                                $res_authorization['expires_in'] = $expires_in;
                                $res_authorization['p_q_inner_data_temp'] = $p_q_inner_data;
                            }

                            \danog\MadelineProto\Logger::log(['Auth key generated'], \danog\MadelineProto\Logger::NOTICE);

                            return $res_authorization;
                        case 'dh_gen_retry':
                            if ($Set_client_DH_params_answer['new_nonce_hash2'] != $new_nonce_hash2) {
                                throw new \danog\MadelineProto\SecurityException('wrong new_nonce_hash_2');
                            }

                            //repeat foreach
                            \danog\MadelineProto\Logger::log(['Retrying Auth'], \danog\MadelineProto\Logger::VERBOSE);
                            break;
                        case 'dh_gen_fail':
                            if ($Set_client_DH_params_answer['new_nonce_hash3'] != $new_nonce_hash3) {
                                throw new \danog\MadelineProto\SecurityException('wrong new_nonce_hash_3');
                            }

                            \danog\MadelineProto\Logger::log(['Auth Failed'], \danog\MadelineProto\Logger::WARNING);
                            break 2;
                        default:
                            throw new \danog\MadelineProto\SecurityException('Response Error');
                            break;
                    }
                }
            } catch (\danog\MadelineProto\SecurityException $e) {
                \danog\MadelineProto\Logger::log(['An exception occurred while generating the authorization key: '.$e->getMessage().' in '.basename($e->getFile(), '.php').' on line '.$e->getLine().'. Retrying...'], \danog\MadelineProto\Logger::WARNING);
            } catch (\danog\MadelineProto\Exception $e) {
                \danog\MadelineProto\Logger::log(['An exception occurred while generating the authorization key: '.$e->getMessage().' in '.basename($e->getFile(), '.php').' on line '.$e->getLine().'. Retrying...'], \danog\MadelineProto\Logger::WARNING);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                \danog\MadelineProto\Logger::log(['An RPCErrorException occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...'], \danog\MadelineProto\Logger::WARNING);
            } finally {
                $this->datacenter->new_outgoing = [];
                $this->datacenter->new_incoming = [];
            }
        }

        throw new \danog\MadelineProto\SecurityException('Auth Failed');
    }

    public function check_G($g_a, $p)
    {

        /*
         * ***********************************************************************
         * Check validity of g_a
         * 1 < g_a < p - 1
         */
        \danog\MadelineProto\Logger::log(['Executing g_a check (1/2)...'], \danog\MadelineProto\Logger::VERBOSE);
        if ($g_a->compare($this->one) <= 0 // 1 < g_a or g_a > 1 or ! g_a <= 1
           || $g_a->compare($p->subtract($this->one)) >= 0 // g_a < dh_prime - 1 or ! g_a >= dh_prime - 1
        ) {
            throw new \danog\MadelineProto\SecurityException('g_a is invalid (1 < g_a < dh_prime - 1 is false).');
        }

        \danog\MadelineProto\Logger::log(['Executing g_a check (2/2)...'], \danog\MadelineProto\Logger::VERBOSE);
        if ($g_a->compare($this->twoe1984) < 0 // gA < 2^{2048-64}
           || $g_a->compare($p->subtract($this->twoe1984)) >= 0 // gA > dhPrime - 2^{2048-64}
        ) {
            throw new \danog\MadelineProto\SecurityException('g_a is invalid (2^1984 < gA < dh_prime - 2^1984 is false).');
        }

        return true;
    }

    public function check_p_g($p, $g)
    {
        /*
        * ***********************************************************************
        * Check validity of dh_prime
        * Is it a prime?
        */
        \danog\MadelineProto\Logger::log(['Executing p/g checks (1/2)...'], \danog\MadelineProto\Logger::VERBOSE);
        if (!$p->isPrime()) {
            throw new \danog\MadelineProto\SecurityException("p isn't a safe 2048-bit prime (p isn't a prime).");
        }

       /*
                * ***********************************************************************
                * Check validity of p
                * Is (p - 1) / 2 a prime?
                *
                * Almost always fails
                */
                /*
                \danog\MadelineProto\Logger::log(['Executing p/g checks (2/3)...'], \danog\MadelineProto\Logger::VERBOSE);
                if (!$p->subtract($this->one)->divide($this->two)[0]->isPrime()) {
                    throw new \danog\MadelineProto\SecurityException("p isn't a safe 2048-bit prime ((p - 1) / 2 isn't a prime).");
                }
                */

                /*
                * ***********************************************************************
                * Check validity of p
                * 2^2047 < p < 2^2048
                */
                \danog\MadelineProto\Logger::log(['Executing p/g checks (2/2)...'], \danog\MadelineProto\Logger::VERBOSE);
        if ($p->compare($this->twoe2047) <= 0 // 2^2047 < p or p > 2^2047 or ! p <= 2^2047
                    || $p->compare($this->twoe2048) >= 0 // p < 2^2048 or ! p >= 2^2048
                ) {
            throw new \danog\MadelineProto\SecurityException("g isn't a safe 2048-bit prime (2^2047 < p < 2^2048 is false).");
        }

                /*
                * ***********************************************************************
                * Check validity of g
                * 1 < g < p - 1
                */
                \danog\MadelineProto\Logger::log(['Executing g check...'], \danog\MadelineProto\Logger::VERBOSE);

        if ($g->compare($this->one) <= 0 // 1 < g or g > 1 or ! g <= 1
                    || $g->compare($p->subtract($this->one)) >= 0 // g < p - 1 or ! g >= p - 1
                ) {
            throw new \danog\MadelineProto\SecurityException('g is invalid (1 < g < p - 1 is false).');
        }

        return true;
    }

    public function get_dh_config()
    {
        $this->getting_state = true;
        $dh_config = $this->method_call('messages.getDhConfig', ['version' => $this->dh_config['version'], 'random_length' => 0]);
        $this->getting_state = false;
        if ($dh_config['_'] === 'messages.dhConfigNotModified') {
            \danog\MadelineProto\Logger::log(\danog\MadelineProto\Logger::VERBOSE, ['DH configuration not modified']);

            return $this->dh_config;
        }
        $dh_config['p'] = new \phpseclib\Math\BigInteger($dh_config['p'], 256);
        $dh_config['g'] = new \phpseclib\Math\BigInteger($dh_config['g']);
        $this->check_p_g($dh_config['p'], $dh_config['g']);

        return $this->dh_config = $dh_config;
    }

    private $temp_requested_secret_chats = [];
    private $secret_chats = [];

    private $temp_requested_calls = [];
    private $calls = [];

    public function accept_secret_chat($params)
    {
        $dh_config = $this->get_dh_config();
        \danog\MadelineProto\Logger::log(['Generating b...'], \danog\MadelineProto\Logger::VERBOSE);
        $b = new \phpseclib\Math\BigInteger($this->random(256), 256);
        $params['g_a'] = new \phpseclib\Math\BigInteger($params['g_a'], 256);
        $this->check_G($params['g_a'], $dh_config['p']);
        $key = ['auth_key' => str_pad($params['g_a']->powMod($b, $dh_config['p'])->toBytes(), 256, chr(0), \STR_PAD_LEFT)];
        $key['fingerprint'] = \danog\PHP\Struct::unpack('<q', substr(sha1($key['auth_key'], true), -8))[0];
        $key['visualization_orig'] = substr(sha1($key['auth_key'], true), 16);
        $key['visualization_46'] = substr(hash('sha256', $key['auth_key'], true), 20);
        $this->secret_chats[$params['id']] = ['key' => $key, 'admin' => false, 'user_id' => $params['admin_id'], 'InputEncryptedChat' => ['_' => 'inputEncryptedChat', 'chat_id' => $params['id'], 'access_hash' => $params['access_hash']], 'in_seq_no_x' => 1, 'out_seq_no_x' => 0, 'layer' => 8, 'ttl' => PHP_INT_MAX, 'ttr' => 100, 'updated' => time(), 'incoming' => [], 'outgoing' => [], 'created' => time(), 'rekeying' => [0]];
        $g_b = $dh_config['g']->powMod($b, $dh_config['p']);
        $this->check_G($g_b, $dh_config['p']);
        $this->notify_layer($params['id']);
        $this->handle_pending_updates();
    }

    public function request_secret_chat($user)
    {
        $user = $this->get_info($user)['InputUser'];
        \danog\MadelineProto\Logger::log(['Creating secret chat with '.$user['user_id'].'...'], \danog\MadelineProto\Logger::VERBOSE);
        $dh_config = $this->get_dh_config();
        \danog\MadelineProto\Logger::log(['Generating a...'], \danog\MadelineProto\Logger::VERBOSE);
        $a = new \phpseclib\Math\BigInteger($this->random(256), 256);
        \danog\MadelineProto\Logger::log(['Generating g_a...'], \danog\MadelineProto\Logger::VERBOSE);
        $g_a = $dh_config['g']->powMod($a, $dh_config['p']);
        $this->check_G($g_a, $dh_config['p']);
        $res = $this->method_call('messages.requestEncryption', ['user_id' => $user, 'g_a' => $g_a->toBytes()]);
        $this->temp_requested_secret_chats[$res['id']] = $a;
        $this->handle_pending_updates();
        $this->get_updates_difference();

        return $res['id'];
    }

    public function request_call($user)
    {
        $user = $this->get_info($user)['InputUser'];
        \danog\MadelineProto\Logger::log(['Calling '.$user['user_id'].'...'], \danog\MadelineProto\Logger::VERBOSE);
        $dh_config = $this->get_dh_config();
        \danog\MadelineProto\Logger::log(['Generating a...'], \danog\MadelineProto\Logger::VERBOSE);
        $a = new \phpseclib\Math\BigInteger($this->random(256), 256);
        \danog\MadelineProto\Logger::log(['Generating g_a...'], \danog\MadelineProto\Logger::VERBOSE);
        $g_a = $dh_config['g']->powMod($a, $dh_config['p']);
        $this->check_G($g_a, $dh_config['p']);
//        $res = $this->method_call('phone.requestCall', ['user_id' => $user, 'g_a' => $g_a->toBytes(), 'protocol' => ['_' => 'phoneCallProtocol', 'min_layer' => $this->settings['tl_schema']['layer'], 'max_layer' => $this->settings['tl_schema']['layer']]]);
        $res = $this->method_call('phone.requestCall', ['user_id' => $user, 'g_a' => $g_a->toBytes(), 'protocol' => ['_' => 'phoneCallProtocol', 'min_layer' => 65, 'max_layer' => 65, 'udp_reflector' => true]]);
        $this->temp_requested_calls[$res['phone_call']['id']] = $a;
        $this->handle_pending_updates();
        $this->get_updates_difference();

        return $res['phone_call']['id'];
    }

    public function complete_secret_chat($params)
    {
        if ($this->secret_chat_status($params['id']) !== 1) {
            \danog\MadelineProto\Logger::log(['Could not find and complete secret chat '.$params['id']]);

            return false;
        }
        $dh_config = $this->get_dh_config();
        $params['g_a_or_b'] = new \phpseclib\Math\BigInteger($params['g_a_or_b'], 256);
        $this->check_G($params['g_a_or_b'], $dh_config['p']);
        $key = ['auth_key' => str_pad($params['g_a_or_b']->powMod($this->temp_requested_secret_chats[$params['id']], $dh_config['p'])->toBytes(), 256, chr(0), \STR_PAD_LEFT)];
        unset($this->temp_requested_secret_chats[$params['id']]);
        $key['fingerprint'] = \danog\PHP\Struct::unpack('<q', substr(sha1($key['auth_key'], true), -8))[0];
        if ($key['fingerprint'] !== $params['key_fingerprint']) {
            $this->method_call('messages.discardEncryption', ['chat_id' => $params['id']]);
            throw new \danog\MadelineProto\SecurityException('Invalid key fingerprint!');
        }
        $key['visualization_orig'] = substr(sha1($key['auth_key'], true), 16);
        $key['visualization_46'] = substr(hash('sha256', $key['auth_key'], true), 20);
        $this->secret_chats[$params['id']] = ['key' => $key, 'admin' => true, 'user_id' => $params['participant_id'], 'InputEncryptedChat' => ['chat_id' => $params['id'], 'access_hash' => $params['access_hash'], '_' => 'inputEncryptedChat'], 'in_seq_no_x' => 0, 'out_seq_no_x' => 1, 'layer' => 8, 'ttl' => PHP_INT_MAX, 'ttr' => 100, 'updated' => time(), 'incoming' => [], 'outgoing' => [], 'created' => time(), 'rekeying' => [0]];
        $this->notify_layer($params['id']);
        $this->handle_pending_updates();
    }

    public function notify_layer($chat)
    {
        $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionNotifyLayer', 'layer' => $this->encrypted_layer]]]);
    }

    private $temp_rekeyed_secret_chats = [];

    public function rekey($chat)
    {
        if ($this->secret_chats[$chat]['rekeying'][0] !== 0) {
            return;
        }
        \danog\MadelineProto\Logger::log(['Rekeying secret chat '.$chat.'...'], \danog\MadelineProto\Logger::VERBOSE);
        $dh_config = $this->get_dh_config();
        \danog\MadelineProto\Logger::log(['Generating a...'], \danog\MadelineProto\Logger::VERBOSE);
        $a = new \phpseclib\Math\BigInteger($this->random(256), 256);
        \danog\MadelineProto\Logger::log(['Generating g_a...'], \danog\MadelineProto\Logger::VERBOSE);
        $g_a = $dh_config['g']->powMod($a, $dh_config['p']);
        $this->check_G($g_a, $dh_config['p']);
        $e = \danog\PHP\Struct::unpack('<q', $this->random(8))[0];
        $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionRequestKey', 'g_a' => $g_a->toBytes(), 'exchange_id' => $e]]]);
        $this->temp_rekeyed_secret_chats[$e] = $a;
        $this->secret_chats[$chat]['rekeying'] = [1, $e];
        $this->handle_pending_updates();
        $this->get_updates_difference();

        return $e;
    }

    public function accept_rekey($chat, $params)
    {
        if ($this->secret_chats[$chat]['rekeying'][0] !== 0) {
            $my = $this->temp_rekeyed_secret_chats[$this->secret_chats[$chat]['rekeying'][1]];
            if ($my['exchange_id'] > $params['exchange_id']) {
                return;
            }
            if ($my['exchange_id'] === $params['exchange_id']) {
                $this->secret_chats[$chat]['rekeying'] = [0];
                $this->rekey($chat);

                return;
            }
        }
        \danog\MadelineProto\Logger::log(['Accepting rekeying of secret chat '.$chat.'...'], \danog\MadelineProto\Logger::VERBOSE);
        $dh_config = $this->get_dh_config();
        \danog\MadelineProto\Logger::log(['Generating b...'], \danog\MadelineProto\Logger::VERBOSE);
        $b = new \phpseclib\Math\BigInteger($this->random(256), 256);
        $params['g_a'] = new \phpseclib\Math\BigInteger($params['g_a'], 256);
        $this->check_G($params['g_a'], $dh_config['p']);
        $key = ['auth_key' => str_pad($params['g_a']->powMod($b, $dh_config['p'])->toBytes(), 256, chr(0), \STR_PAD_LEFT)];
        $key['fingerprint'] = \danog\PHP\Struct::unpack('<q', substr(sha1($key['auth_key'], true), -8))[0];
        $key['visualization_orig'] = $this->secret_chats[$chat]['key']['visualization_orig'];
        $key['visualization_46'] = substr(hash('sha256', $key['auth_key'], true), 20);
        $this->temp_rekeyed_secret_chats[$params['exchange_id']] = $key;
        $this->secret_chats[$chat]['rekeying'] = [2, $params['exchange_id']];
        $g_b = $dh_config['g']->powMod($b, $dh_config['p']);
        $this->check_G($g_b, $dh_config['p']);
        $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionAcceptKey', 'g_b' => $g_b->toBytes(), 'exchange_id' => $params['exchange_id'], 'key_fingerprint' => $key['fingerprint']]]]);
        $this->handle_pending_updates();
        $this->get_updates_difference();
    }

    public function commit_rekey($chat, $params)
    {
        if ($this->secret_chats[$chat]['rekeying'][0] !== 1) {
            return;
        }
        \danog\MadelineProto\Logger::log(['Committing rekeying of secret chat '.$chat.'...'], \danog\MadelineProto\Logger::VERBOSE);
        $dh_config = $this->get_dh_config();
        $params['g_b'] = new \phpseclib\Math\BigInteger($params['g_b'], 256);
        $this->check_G($params['g_b'], $dh_config['p']);
        $key = ['auth_key' => str_pad($params['g_b']->powMod($this->temp_rekeyed_secret_chats[$params['exchange_id']], $dh_config['p'])->toBytes(), 256, chr(0), \STR_PAD_LEFT)];
        $key['fingerprint'] = \danog\PHP\Struct::unpack('<q', substr(sha1($key['auth_key'], true), -8))[0];
        $key['visualization_orig'] = $this->secret_chats[$chat]['key']['visualization_orig'];
        $key['visualization_46'] = substr(hash('sha256', $key['auth_key'], true), 20);
        if ($key['fingerprint'] !== $params['key_fingerprint']) {
            $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionAbortKey', 'exchange_id' => $params['exchange_id']]]]);
            throw new \danog\MadelineProto\SecurityException('Invalid key fingerprint!');
        }
        $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionCommitKey', 'exchange_id' => $params['exchange_id'], 'key_fingerprint' => $key['fingerprint']]]]);
        unset($this->temp_rekeyed_secret_chats[$chat]);
        $this->secret_chats[$chat]['rekeying'] = [0];
        $this->secret_chats[$chat]['key'] = $key;
        $this->secret_chats[$chat]['ttr'] = 100;
        $this->secret_chats[$chat]['updated'] = time();

        $this->handle_pending_updates();
        $this->get_updates_difference();
    }

    public function complete_rekey($chat, $params)
    {
        if ($this->secret_chats[$chat]['rekeying'][0] !== 2) {
            return;
        }
        if ($this->temp_rekeyed_secret_chats['fingerprint'] !== $params['key_fingerprint']) {
            $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionAbortKey', 'exchange_id' => $params['exchange_id']]]]);
            throw new \danog\MadelineProto\SecurityException('Invalid key fingerprint!');
        }
        \danog\MadelineProto\Logger::log(['Completing rekeying of secret chat '.$chat.'...'], \danog\MadelineProto\Logger::VERBOSE);
        $this->secret_chats[$chat]['rekeying'] = [0];
        $this->secret_chats[$chat]['key'] = $this->temp_rekeyed_secret_chats;
        $this->secret_chats[$chat]['ttr'] = 100;
        $this->secret_chats[$chat]['updated'] = time();
        unset($this->temp_rekeyed_secret_chats[$params['exchange_id']]);
        $this->method_call('messages.sendEncryptedService', ['peer' => $chat, 'message' => ['_' => 'decryptedMessageService', 'action' => ['_' => 'decryptedMessageActionNoop']]]);
    }

    public function secret_chat_status($chat)
    {
        if (isset($this->secret_chats[$chat])) {
            return 2;
        }
        if (isset($this->temp_requested_secret_chats[$chat])) {
            return 1;
        }

        return 0;
    }

    public function get_secret_chat($chat)
    {
        return $this->secret_chats[$chat];
    }

    public function bind_temp_auth_key($expires_in)
    {
        for ($retry_id_total = 1; $retry_id_total <= $this->settings['max_tries']['authorization']; $retry_id_total++) {
            try {
                \danog\MadelineProto\Logger::log(['Binding authorization keys...'], \danog\MadelineProto\Logger::VERBOSE);
                $nonce = \danog\PHP\Struct::unpack('<q', $this->random(8))[0];
                $expires_at = time() + $expires_in;
                $temp_auth_key_id = \danog\PHP\Struct::unpack('<q', $this->datacenter->temp_auth_key['id'])[0];
                $perm_auth_key_id = \danog\PHP\Struct::unpack('<q', $this->datacenter->auth_key['id'])[0];
                $temp_session_id = \danog\PHP\Struct::unpack('<q', $this->datacenter->session_id)[0];
                $message_data = $this->serialize_object(['type' => 'bind_auth_key_inner'],
            [
                'nonce'                       => $nonce,
                'temp_auth_key_id'            => $temp_auth_key_id,
                'perm_auth_key_id'            => $perm_auth_key_id,
                'temp_session_id'             => $temp_session_id,
                'expires_at'                  => $expires_at,
            ]
        );
                $int_message_id = $this->generate_message_id();

                $message_id = \danog\PHP\Struct::pack('<Q', $int_message_id);
                $seq_no = 0;
                $encrypted_data = $this->random(16).$message_id.\danog\PHP\Struct::pack('<II', $seq_no, strlen($message_data)).$message_data;
                $message_key = substr(sha1($encrypted_data, true), -16);
                $padding = $this->random($this->posmod(-strlen($encrypted_data), 16));
                list($aes_key, $aes_iv) = $this->aes_calculate($message_key, $this->datacenter->auth_key['auth_key']);
                $encrypted_message = $this->datacenter->auth_key['id'].$message_key.$this->ige_encrypt($encrypted_data.$padding, $aes_key, $aes_iv);
                $res = $this->method_call('auth.bindTempAuthKey', ['perm_auth_key_id' => $perm_auth_key_id, 'nonce' => $nonce, 'expires_at' => $expires_at, 'encrypted_message' => $encrypted_message], ['message_id' => $int_message_id]);
                if ($res === true) {
                    \danog\MadelineProto\Logger::log(['Successfully binded temporary and permanent authorization keys.'], \danog\MadelineProto\Logger::NOTICE);

                    return true;
                }
            } catch (\danog\MadelineProto\SecurityException $e) {
                \danog\MadelineProto\Logger::log(['An exception occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...'], \danog\MadelineProto\Logger::WARNING);
            } catch (\danog\MadelineProto\Exception $e) {
                \danog\MadelineProto\Logger::log(['An exception occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...'], \danog\MadelineProto\Logger::WARNING);
            } catch (\danog\MadelineProto\RPCErrorException $e) {
                \danog\MadelineProto\Logger::log(['An RPCErrorException occurred while generating the authorization key: '.$e->getMessage().' Retrying (try number '.$retry_id_total.')...'], \danog\MadelineProto\Logger::WARNING);
            } finally {
                $this->datacenter->new_outgoing = [];
                $this->datacenter->new_incoming = [];
            }
        }
        throw new \danog\MadelineProto\SecurityException('An error occurred while binding temporary and permanent authorization keys.');
    }
}
