<?php
require_once DIR_SEI_WEB . '/SEI.php';

class PenUtils
{

    public static function acaoURL($acao,$origem,$retorno,$extras=null){

        $objSessao = SessaoSEI::getInstance();

        $url= 'controlador.php?acao='.$acao
        .'&acao_origem='.$origem
        .'&acao_retorno='.$retorno;

        if($extras!=null){
            foreach ($extras as $key => $value) {
                $url .= "&" . $key . "=" . $value;
            }
        }

        return $objSessao->assinarLink($url);
    }


    public static function createJsonAcaoSEI()
    {

        $arr = [
            "PEN_RECURSO_BASE" => PEN_RECURSO_BASE,
            "acao" => $_GET['acao'],
            "acao_origem" => $_GET['acao_origem'],
            "acao_retorno" => $_GET['acao_retorno']
        ];

        return json_encode($arr);


    }



}
