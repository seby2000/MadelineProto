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
 * Manages packing and unpacking of messages, and the list of sent and received messages.
 */
trait MessageHandler
{
    /**
     * Forming the message frame and sending message to server
     * :param message: byte string to send.
     */
    public function send_message($message_data, $content_related, $aargs = [])
    {
        if (!isset($aargs['message_id']) || $aargs['message_id'] === null) {
            $int_message_id = $this->generate_message_id();
        } else {
            $int_message_id = $aargs['message_id'];
        }
        if (!is_int($int_message_id)) {
            throw new \danog\MadelineProto\Exception("Specified message id isn't an integer");
        }

        $message_id = \danog\PHP\Struct::pack('<Q', $int_message_id);
        if ($this->datacenter->temp_auth_key['auth_key'] === null || $this->datacenter->temp_auth_key['server_salt'] === null) {
            $message = str_repeat(chr(0), 8).$message_id.\danog\PHP\Struct::pack('<I', strlen($message_data)).$message_data;
        } else {
            $seq_no = $this->generate_seq_no($content_related);
            $data2enc = \danog\PHP\Struct::pack('<q', $this->datacenter->temp_auth_key['server_salt']).$this->datacenter->session_id.$message_id.\danog\PHP\Struct::pack('<II', $seq_no, strlen($message_data)).$message_data;
            $padding = $this->random($this->posmod(-strlen($data2enc), 16));
            $message_key = substr(sha1($data2enc, true), -16);
            list($aes_key, $aes_iv) = $this->aes_calculate($message_key, $this->datacenter->temp_auth_key['auth_key']);
            $message = $this->datacenter->temp_auth_key['id'].$message_key.$this->ige_encrypt($data2enc.$padding, $aes_key, $aes_iv);
            $this->datacenter->outgoing_messages[$int_message_id]['seq_no'] = $seq_no;
        }
        $this->datacenter->outgoing_messages[$int_message_id]['response'] = -1;
        $this->datacenter->send_message($message);

        return $int_message_id;
    }

    /**
     * Reading connection and receiving message from server.
     */
    public function recv_message()
    {
        $payload = $this->datacenter->read_message();
        if (strlen($payload) === 4) {
            $error = \danog\PHP\Struct::unpack('<i', $payload)[0];
            if ($error === -404) {
                if ($this->datacenter->temp_auth_key != null) {
                    \danog\MadelineProto\Logger::log(['WARNING: Resetting auth key...'], \danog\MadelineProto\Logger::WARNING);
                    $this->datacenter->temp_auth_key = null;
                    $this->init_authorization();
                    $this->config = $this->write_client_info('help.getConfig');
                    $this->parse_config();
                    throw new \danog\MadelineProto\Exception('I had to recreate the temporary authorization key');
                }
            }
            throw new \danog\MadelineProto\RPCErrorException($error, $error);
        }
        $auth_key_id = substr($payload, 0, 8);
        if ($auth_key_id === str_repeat(chr(0), 8)) {
            list($message_id, $message_length) = \danog\PHP\Struct::unpack('<QI', substr($payload, 8, 12));
            $this->check_message_id($message_id, false);
            $message_data = substr($payload, 20, $message_length);
        } elseif ($auth_key_id === $this->datacenter->temp_auth_key['id']) {
            $message_key = substr($payload, 8, 16);
            $encrypted_data = substr($payload, 24);
            list($aes_key, $aes_iv) = $this->aes_calculate($message_key, $this->datacenter->temp_auth_key['auth_key'], 'from server');
            $decrypted_data = $this->ige_decrypt($encrypted_data, $aes_key, $aes_iv);

            $server_salt = \danog\PHP\Struct::unpack('<q', substr($decrypted_data, 0, 8))[0];
            if ($server_salt != $this->datacenter->temp_auth_key['server_salt']) {
                //\danog\MadelineProto\Logger::log(['WARNING: Server salt mismatch (my server salt '.$this->datacenter->temp_auth_key['server_salt'].' is not equal to server server salt '.$server_salt.').'], \danog\MadelineProto\Logger::WARNING);
            }

            $session_id = substr($decrypted_data, 8, 8);
            if ($session_id != $this->datacenter->session_id) {
                throw new \danog\MadelineProto\Exception('Session id mismatch.');
            }

            $message_id = \danog\PHP\Struct::unpack('<Q', substr($decrypted_data, 16, 8))[0];
            $this->check_message_id($message_id, false);

            $seq_no = \danog\PHP\Struct::unpack('<I', substr($decrypted_data, 24, 4))[0];
            // Dunno how to handle any incorrect sequence numbers

            $message_data_length = \danog\PHP\Struct::unpack('<I', substr($decrypted_data, 28, 4))[0];

            if ($message_data_length > strlen($decrypted_data)) {
                throw new \danog\MadelineProto\SecurityException('message_data_length is too big');
            }

            if ((strlen($decrypted_data) - 32) - $message_data_length > 15) {
                throw new \danog\MadelineProto\SecurityException('difference between message_data_length and the length of the remaining decrypted buffer is too big');
            }

            if ($message_data_length < 0) {
                throw new \danog\MadelineProto\SecurityException('message_data_length not positive');
            }

            if ($message_data_length % 4 != 0) {
                throw new \danog\MadelineProto\SecurityException('message_data_length not divisible by 4');
            }

            $message_data = substr($decrypted_data, 32, $message_data_length);
            if ($message_key != substr(sha1(substr($decrypted_data, 0, 32 + $message_data_length), true), -16)) {
                throw new \danog\MadelineProto\SecurityException('msg_key mismatch');
            }
            $this->datacenter->incoming_messages[$message_id]['seq_no'] = $seq_no;
        } else {
            throw new \danog\MadelineProto\SecurityException('Got unknown auth_key id');
        }
        $deserialized = $this->deserialize($message_data);
        $this->datacenter->incoming_messages[$message_id]['content'] = $deserialized;
        $this->datacenter->incoming_messages[$message_id]['response'] = -1;
        $this->datacenter->new_incoming[$message_id] = $message_id;
        $this->handle_messages();
    }

    public function encrypt_secret_message($chat_id, $message)
    {
        if (!isset($this->secret_chats[$chat_id])) {
            \danog\MadelineProto\Logger::log('I do not have the secret chat '.$chat_id.' in the database, skipping message...');

            return false;
        }
        $message = $this->serialize_object(['type' => $message['_']], $message, $this->secret_chats[$chat_id]['layer']);
        $this->secret_chats[$chat_id]['outgoing'][] = $message;
        $this->secret_chats[$chat_id]['ttr']--;
        if (($this->secret_chats[$chat_id]['ttr'] <= 0 || time() - $this->secret_chats[$chat_id]['updated'] > 7 * 24 * 60 * 60) && $this->secret_chats[$chat_id]['rekeying'] === 0) {
            $this->rekey($chat_id);
        }

        $message = \danog\PHP\Struct::pack('<I', strlen($message)).$message;
        $message_key = substr(sha1($message, true), -16);
        list($aes_key, $aes_iv) = $this->aes_calculate($message_key, $this->secret_chats[$chat_id]['key']['auth_key'], 'to server');
        $padding = $this->random($this->posmod(-strlen($message), 16));
        $message = $this->secret_chats[$chat_id]['key']['fingerprint'].$message_key.$this->ige_encrypt($message.$padding, $aes_key, $aes_iv);

        return $message;
    }

    public function handle_encrypted_update($message)
    {
        if (!isset($this->secret_chats[$message['message']['chat_id']])) {
            \danog\MadelineProto\Logger::log('I do not have the secret chat '.$message['message']['chat_id'].' in the database, skipping message...');

            return false;
        }
        $auth_key_id = \danog\PHP\Struct::unpack('<q', substr($message['message']['bytes'], 0, 8))[0];
        if ($auth_key_id !== $this->secret_chats[$message['message']['chat_id']]['key']['fingerprint']) {
            throw new \danog\MadelineProto\SecurityException('Key fingerprint mismatch');
        }
        $message_key = substr($message['message']['bytes'], 8, 16);
        $encrypted_data = substr($message['message']['bytes'], 24);
        list($aes_key, $aes_iv) = $this->aes_calculate($message_key, $this->secret_chats[$message['message']['chat_id']]['key']['auth_key'], 'to server');
        $decrypted_data = $this->ige_decrypt($encrypted_data, $aes_key, $aes_iv);
        $message_data_length = \danog\PHP\Struct::unpack('<I', substr($decrypted_data, 0, 4))[0];
        if ($message_data_length > strlen($decrypted_data)) {
            throw new \danog\MadelineProto\SecurityException('message_data_length is too big');
        }

        if ((strlen($decrypted_data) - 4) - $message_data_length > 15) {
            throw new \danog\MadelineProto\SecurityException('difference between message_data_length and the length of the remaining decrypted buffer is too big');
        }

        if ($message_data_length % 4 != 0) {
            throw new \danog\MadelineProto\SecurityException('message_data_length not divisible by 4');
        }
        $message_data = substr($decrypted_data, 4, $message_data_length);
        if ($message_key != substr(sha1(substr($decrypted_data, 0, 4 + $message_data_length), true), -16)) {
            throw new \danog\MadelineProto\SecurityException('msg_key mismatch');
        }
        $deserialized = $this->deserialize($message_data);
        if (strlen($deserialized['random_bytes']) < 15) {
            throw new \danog\MadelineProto\SecurityException('random_bytes is too short');
        }
        $this->secret_chats[$message['message']['chat_id']]['ttr']--;
        if (($this->secret_chats[$message['message']['chat_id']]['ttr'] <= 0 || time() - $this->secret_chats[$message['message']['chat_id']]['updated'] > 7 * 24 * 60 * 60) && $this->secret_chats[$message['message']['chat_id']]['rekeying'] === 0) {
            $this->rekey($message['message']['chat_id']);
        }
        unset($message['message']['bytes']);
        $message['message']['decrypted_message'] = $deserialized;
        $this->handle_decrypted_update($message);
    }
}
