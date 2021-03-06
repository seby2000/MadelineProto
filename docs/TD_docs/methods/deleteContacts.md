---
title: deleteContacts
description: Deletes users from contacts list
---
## Method: deleteContacts  
[Back to methods index](index.md)


Deletes users from contacts list

### Params:

| Name     |    Type       | Required | Description |
|----------|:-------------:|:--------:|------------:|
|user\_ids|Array of [int](../types/int.md) | Yes|Identifiers of users to be deleted|


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

$Ok = $MadelineProto->deleteContacts(['user_ids' => [int], ]);
```

Or, if you're into Lua:

```
Ok = deleteContacts({user_ids={int}, })
```

