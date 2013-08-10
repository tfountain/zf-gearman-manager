#!/usr/bin/env php
<?php

if(!class_exists("GearmanManager\Bridge\GearmanPeclManager")){
    require __DIR__."/../src/ZfGearmanManager/ZfGearmanPeclManager.php";
}

declare(ticks = 1);

$gm = new ZfGearmanManager\ZfGearmanPeclManager();
