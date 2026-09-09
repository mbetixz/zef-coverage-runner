<?php
declare(strict_types=1); require __DIR__.'/_assert.php'; $root=dirname(__DIR__,2); $s=architecture_source($root); foreach(['Monolog\\','Twig\\','Symfony\\Component\\Console\\','League\\Event\\','GuzzleHttp\\','Laminas\\Diactoros\\','Nyholm\\'] as $v) architecture_check(!str_contains($s,$v),"production core has no vendor coupling: $v");
