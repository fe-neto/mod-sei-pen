<?php

class HipoteseLegalController extends PenController
{

    //injecao de $data para testes, seria ver se tem "hipoteseLocal" por exemplo
    public function listar($data = [])
    {

        try {

            $data = [
                "acao" => "pen_map_hipotese_legal_envio_listar",
            ];

            if (!empty($_POST['id_barramento'])) {

                $data["filtroBarramento"] = $_POST['id_barramento'];
            }

            if (!empty($_POST['id_hipotese_legal'])) {

                $data["filtroLocal"] = $_POST['id_hipotese_legal'];
            }

            SessaoSEI::getInstance()
                ->validarAuditarPermissao($data["acao"], __METHOD__, $data);


            $hipoteseRN = new PenHipoteseLegalv2RN();

            $data["hipoteseLocal"] = $hipoteseRN->getHipotesesLocais($data);
            $data["hipoteseRemota"] = $hipoteseRN->getHipotesesRemotas($data);
            $data["mapeamento"] = $hipoteseRN->getMapeamentoHipoteses($data);



            define('PEN_RECURSO_ATUAL', 'pen_map_hipotese_legal_envio_listar');
            define('PEN_RECURSO_BASE', 'pen_map_hipotese_legal_envio');
            define('PEN_PAGINA_TITULO', 'Mapeamento de Hipóteses Legais para Envio');
            define('PEN_PAGINA_GET_ID', 'id_mapeamento');

            //chama a view
            self::showView("HipoteseLegalView", $data);


        } catch (Exception $e) {
            throw new InfraException('Erro controlador HipoteseLegalController', $e);
        }
    }



    public function excluir($data = [])
    {

        try {

            if (!array_key_exists('hdnInfraItensSelecionados', $_POST)) {
                throw new InfraException('Nenhum Registro foi selecionado para executar esta ação');
            }

            $data = [
                "acao" => "pen_map_hipotese_legal_envio_excluir"
            ];

            SessaoSEI::getInstance()
                ->validarAuditarPermissao($data["acao"], __METHOD__, $data);


            $hipoteseRN = new PenHipoteseLegalv2RN();
            $data["hipotesesDelete"] = explode(',', $_POST['hdnInfraItensSelecionados']);
            $data["mensagemExclusao"] = $hipoteseRN->deleteHipoteses($data);

            $objPagina = PaginaSEI::getInstance();
            $objPagina->adicionarMensagem("Mapeamento de Hipóteses Legais para Envio excluído com sucesso", InfraPagina::$TIPO_MSG_AVISO);

            header('Location: ' . PenUtils::acaoURL("pen_map_hipotese_legal_envio_listar",$data["acao"],$_GET['acao_retorno'])  );
            exit(0);

        } catch (Exception $e) {
            throw new InfraException('Erro controlador HipoteseLegalController', $e);
        }
    }
}
