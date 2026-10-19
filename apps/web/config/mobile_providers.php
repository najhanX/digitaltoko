<?php

return [
    'telkomsel' => ['name' => 'Telkomsel', 'prefixes' => ['0811', '0812', '0813', '0821', '0822', '0823', '0851', '0852', '0853'], 'pattern' => '/\btelkomsel\b|\bsimpati\b|\bkartu as\b/i'],
    'indosat' => ['name' => 'Indosat / IM3', 'prefixes' => ['0814', '0815', '0816', '0855', '0856', '0857', '0858'], 'pattern' => '/\bindosat\b|\bim3\b|\bmentari\b/i'],
    'xl' => ['name' => 'XL', 'prefixes' => ['0817', '0818', '0819', '0859', '0877', '0878'], 'pattern' => '/\bxl\b|\blive[ .]?on\b/i'],
    'axis' => ['name' => 'AXIS', 'prefixes' => ['0831', '0832', '0833', '0838'], 'pattern' => '/\baxis\b/i'],
    'tri' => ['name' => 'Tri', 'prefixes' => ['0895', '0896', '0897', '0898', '0899'], 'pattern' => '/\btri\b|\bthree\b|\b3\b/i'],
    'smartfren' => ['name' => 'Smartfren', 'prefixes' => ['0881', '0882', '0883', '0884', '0885', '0886', '0887', '0888', '0889'], 'pattern' => '/\bsmartfren\b/i'],
    'byu' => ['name' => 'by.U', 'prefixes' => [], 'pattern' => '/\bby[ .]?u\b/i'],
];
