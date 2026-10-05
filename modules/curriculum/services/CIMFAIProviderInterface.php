<?php

interface CIMFAIProviderInterface
{
    public function request(array $config, array $prompt): array;
}
