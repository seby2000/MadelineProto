---
title: auth.exportAuthorization
description: auth.exportAuthorization parameters, return type and example
---
## Method: auth.exportAuthorization  
[Back to methods index](index.md)


### Parameters:

| Name     |    Type       | Required |
|----------|:-------------:|---------:|
|dc\_id|[int](../types/int.md) | Yes|


### Return type: [auth\_ExportedAuthorization](../types/auth_ExportedAuthorization.md)

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

$auth_ExportedAuthorization = $MadelineProto->auth->exportAuthorization(['dc_id' => int, ]);
```

Or, if you're into Lua:

```
auth_ExportedAuthorization = auth.exportAuthorization({dc_id=int, })
```

