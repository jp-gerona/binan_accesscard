<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

/** Configuration for the local family-media registry and storage boundary. */
class FamilyMediaSettings extends BaseConfig
{
    public string $root = '';
    public int $photoMaxBytes = 5242880;
    public int $signatureMaxBytes = 1048576;
    public int $maxDimension = 4096;
}
