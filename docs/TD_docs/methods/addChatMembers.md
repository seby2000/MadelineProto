---
title: addChatMembers
description: Adds many new members to the chat. Currently, available only for channels. Can't be used to join the channel. Member will not be added until chat state will be synchronized with the server. Member will not be added if application is killed before it can send request to the server
---
## Method: addChatMembers  
[Back to methods index](index.md)


Adds many new members to the chat. Currently, available only for channels. Can't be used to join the channel. Member will not be added until chat state will be synchronized with the server. Member will not be added if application is killed before it can send request to the server

### Params:

| Name     |    Type       | Required | Description |
|----------|:-------------:|:--------:|------------:|
|chat\_id|[long](../types/long.md) | Yes|Chat identifier|
|user\_ids|Array of [int](../types/int.md) | Yes|Identifiers of the users to add|


### Return type: [Ok](../types/Ok.md)

### Example:


```
$MadelineProto = new \danog\MadelineProto\API();
if (isset($token)) {
    $this->bot_login($token);
}
if (isset($number)) {
    $sentCode = $MadelineProto->phone_login($number);
    echo 'Enter the code you received: ';
    $code = '';
    for ($x = 0; $x < $sentCode['type']['length']; $x++) {
        $code .= fgetc(STDIN);
    }
    $MadelineProto->complete_phone_login($code);
}

$Ok = $MadelineProto->addChatMembers(['chat_id' => long, 'user_ids' => [int], ]);
```

Or, if you're into Lua:

```
Ok = addChatMembers({chat_id=long, user_ids={int}, })
```

