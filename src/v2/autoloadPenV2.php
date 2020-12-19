<?php

spl_autoload_register("autoloadPenv2");


function autoloadPenv2($strClasse) {

    $paths=[];
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'bd'.DIRECTORY_SEPARATOR.$strClasse.'.php';
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'controllers'.DIRECTORY_SEPARATOR.$strClasse.'.php';
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'facade'.DIRECTORY_SEPARATOR.$strClasse.'.php';
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'rn'.DIRECTORY_SEPARATOR.$strClasse.'.php';
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'utils'.DIRECTORY_SEPARATOR.$strClasse.'.php';
    $paths[] = dirname(__FILE__).DIRECTORY_SEPARATOR.'view'.DIRECTORY_SEPARATOR.$strClasse.'.php';

    foreach ($paths as $path) {
        if (file_exists($path)){
            require_once $path;
            return;
            }
    }


}

