<?php
function validated_return_to($value): string
{
    if (!is_string($value)) {
        return '';
    }

    // 仅允许返回本站三种详情页，避免任意网址进入登录后的跳转。
    if (!preg_match('/\A(?:player|team|tournament)_detail\.php\?id=[1-9][0-9]{0,9}(?:#comments)?\z/', $value)) {
        return '';
    }

    return $value;
}
