<?php

// استثناء يحمل رمز حالة HTTP، يقابل ApiError في نسخة Node.js
class ApiException extends Exception
{
    public $statusCode;

    public function __construct($statusCode, $message)
    {
        parent::__construct($message);
        $this->statusCode = $statusCode;
    }
}
