---
title: messages.getDialogs
description: messages.getDialogs parameters, return type and example
---
## Method: messages.getDialogs  
[Back to methods index](index.md)


### Parameters:

| Name     |    Type       | Required |
|----------|:-------------:|---------:|
|offset|[int](../types/int.md) | Yes|
|max\_id|[int](../types/int.md) | Yes|
|limit|[int](../types/int.md) | Yes|


### Return type: [messages\_Dialogs](../types/messages_Dialogs.md)

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

$messages_Dialogs = $MadelineProto->messages->getDialogs(['offset' => int, 'max_id' => int, 'limit' => int, ]);
```

Or, if you're into Lua:

```
messages_Dialogs = messages.getDialogs({offset=int, max_id=int, limit=int, })
```

