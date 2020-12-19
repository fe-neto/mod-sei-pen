<?php

abstract class PenController
{


    public static function showView($view, $data = [])
    {

        require dirname(__FILE__) . "/../views/" . $view . ".php";
    }

    public static function includeJs($arqName)
    {

        return PENIntegracao::getDiretoriov2() . "/views/js/$arqName.js";
    }


    public static function implicitCast(SeiFacadeWS $seiFacade)
    {
        return $seiFacade;
    }

    public static function includeCss($arqName)
    {

        return PENIntegracao::getDiretoriov2() . "/views/css/$arqName.css";
    }




}
