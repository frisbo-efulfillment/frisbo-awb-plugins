<?php

class FrisboApiException extends Exception
{
    private $httpStatus;

    public function __construct($message, $httpStatus = 0, Exception $previous = null)
    {
        $this->httpStatus = (int) $httpStatus;
        parent::__construct($message, (int) $httpStatus, $previous);
    }

    public function getHttpStatus()
    {
        return $this->httpStatus;
    }
}
