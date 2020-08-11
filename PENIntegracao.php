<?php

class PENIntegracao extends SeiIntegracao {

    const COMPATIBILIDADE_MODULO_SEI = array('3.0.5', '3.0.6', '3.0.7', '3.0.8', '3.0.9', '3.0.11', '3.0.12', '3.0.13', '3.0.14', '3.0.15', '3.1.0', '3.1.1', '3.1.2', '3.1.3', '3.1.4', '3.1.5');

    public function getNome() {
        return 'Integra��o Processo Eletr�nico Nacional - PEN';
    }

    public function getVersao() {
        return '1.5.4';
    }

    public function getInstituicao() {
        return 'Minist�rio da Economia - ME (Projeto Colaborativo no Github)';
    }

    public function montarBotaoProcesso(ProcedimentoAPI $objSeiIntegracaoDTO) {

        $objProcedimentoDTO = new ProcedimentoDTO();
        $objProcedimentoDTO->setDblIdProcedimento($objSeiIntegracaoDTO->getIdProcedimento());
        $objProcedimentoDTO->retTodos();

        $objProcedimentoRN = new ProcedimentoRN();
        $objProcedimentoDTO = $objProcedimentoRN->consultarRN0201($objProcedimentoDTO);

        $objSessaoSEI = SessaoSEI::getInstance();
        $objPaginaSEI = PaginaSEI::getInstance();
        $strAcoesProcedimento = "";

        $dblIdProcedimento = $objProcedimentoDTO->getDblIdProcedimento();
        $numIdUsuario = SessaoSEI::getInstance()->getNumIdUsuario();
        $numIdUnidadeAtual = SessaoSEI::getInstance()->getNumIdUnidadeAtual();
        $objInfraParametro = new InfraParametro(BancoSEI::getInstance());

        //Verifica se o processo encontra-se aberto na unidade atual
        $objAtividadeRN = new AtividadeRN();
        $objPesquisaPendenciaDTO = new PesquisaPendenciaDTO();
        $objPesquisaPendenciaDTO->setDblIdProtocolo($dblIdProcedimento);
        $objPesquisaPendenciaDTO->setNumIdUsuario($numIdUsuario);
        $objPesquisaPendenciaDTO->setNumIdUnidade($numIdUnidadeAtual);
        $objPesquisaPendenciaDTO->setStrSinMontandoArvore('N');
        $arrObjProcedimentoDTO = $objAtividadeRN->listarPendenciasRN0754($objPesquisaPendenciaDTO);
        $bolFlagAberto = count($arrObjProcedimentoDTO) == 1;

        //Verifica��o da Restri��o de Acesso � Funcionalidade
        $bolAcaoExpedirProcesso = $objSessaoSEI->verificarPermissao('pen_procedimento_expedir');
        $objExpedirProcedimentoRN = new ExpedirProcedimentoRN();
        $objProcedimentoDTO = $objExpedirProcedimentoRN->consultarProcedimento($dblIdProcedimento);

        $bolProcessoEstadoNormal = !in_array($objProcedimentoDTO->getStrStaEstadoProtocolo(), array(
            ProtocoloRN::$TE_PROCEDIMENTO_SOBRESTADO,
            ProtocoloRN::$TE_PROCEDIMENTO_BLOQUEADO
        ));

        //Apresenta o bot�o de expedir processo
        if ($bolFlagAberto && $bolAcaoExpedirProcesso && $bolProcessoEstadoNormal && $objProcedimentoDTO->getStrStaNivelAcessoGlobalProtocolo() != ProtocoloRN::$NA_SIGILOSO) {

            $objPenUnidadeDTO = new PenUnidadeDTO();
            $objPenUnidadeDTO->retNumIdUnidade();
            $objPenUnidadeDTO->setNumIdUnidade($numIdUnidadeAtual);
            $objPenUnidadeRN = new PenUnidadeRN();

            if($objPenUnidadeRN->contar($objPenUnidadeDTO) != 0) {
                $numTabBotao = $objPaginaSEI->getProxTabBarraComandosSuperior();
                $strAcoesProcedimento .= '<a id="validar_expedir_processo" href="' . $objPaginaSEI->formatarXHTML($objSessaoSEI->assinarLink('controlador.php?acao=pen_procedimento_expedir&acao_origem=procedimento_visualizar&acao_retorno=arvore_visualizar&id_procedimento=' . $dblIdProcedimento . '&arvore=1')) . '" tabindex="' . $numTabBotao . '" class="botaoSEI"><img class="infraCorBarraSistema" src="' . $this->getDiretorioImagens() . '/pen_expedir_procedimento.gif" alt="Envio Externo de Processo" title="Envio Externo de Processo" /></a>';
            }
        }

        //Apresenta o bot�o da p�gina de recibos
        if($bolAcaoExpedirProcesso){
            $objProcessoEletronicoDTO = new ProcessoEletronicoDTO();
            $objProcessoEletronicoDTO->retDblIdProcedimento();
            $objProcessoEletronicoDTO->setDblIdProcedimento($dblIdProcedimento);
            $objProcessoEletronicoRN = new ProcessoEletronicoRN();
            if($objProcessoEletronicoRN->contar($objProcessoEletronicoDTO) != 0){
                $strAcoesProcedimento .= '<a href="' . $objSessaoSEI->assinarLink('controlador.php?acao=pen_procedimento_estado&acao_origem=procedimento_visualizar&acao_retorno=arvore_visualizar&id_procedimento=' . $dblIdProcedimento . '&arvore=1') . '" tabindex="' . $numTabBotao . '" class="botaoSEI">';
                $strAcoesProcedimento .= '<img class="infraCorBarraSistema" src="' . $this->getDiretorioImagens() . '/pen_consultar_recibos.png" alt="Consultar Recibos" title="Consultar Recibos"/>';
                $strAcoesProcedimento .= '</a>';
            }
        }

        //Apresenta o bot�o de cancelar tr�mite
        $objAtividadeDTO = $objExpedirProcedimentoRN->verificarProcessoEmExpedicao($objSeiIntegracaoDTO->getIdProcedimento());
        if ($objAtividadeDTO && $objAtividadeDTO->getNumIdTarefa() == ProcessoEletronicoRN::obterIdTarefaModulo(ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_EXPEDIDO)) {
            $strAcoesProcedimento .= '<a href="' . $objPaginaSEI->formatarXHTML($objSessaoSEI->assinarLink('controlador.php?acao=pen_procedimento_cancelar_expedir&acao_origem=procedimento_visualizar&acao_retorno=arvore_visualizar&id_procedimento=' . $dblIdProcedimento . '&arvore=1')) . '" tabindex="' . $numTabBotao . '" class="botaoSEI">';
            $strAcoesProcedimento .= '<img class="infraCorBarraSistema" src="' . $this->getDiretorioImagens() . '/pen_cancelar_tramite.gif" alt="Cancelar Tramita��o Externa" title="Cancelar Tramita��o Externa" />';
            $strAcoesProcedimento .= '</a>';
        }

        return array($strAcoesProcedimento);
    }

    public function montarIconeControleProcessos($arrObjProcedimentoAPI = array()) {

        $arrStrIcone = array();
        $arrDblIdProcedimento = array();

        foreach ($arrObjProcedimentoAPI as $ObjProcedimentoAPI) {
            $arrDblIdProcedimento[] = $ObjProcedimentoAPI->getIdProcedimento();
        }

        $objProcedimentoDTO = new ProcedimentoDTO();
        $objProcedimentoDTO->setDblIdProcedimento($arrDblIdProcedimento, InfraDTO::$OPER_IN);
        $objProcedimentoDTO->retDblIdProcedimento();
        $objProcedimentoDTO->retStrStaEstadoProtocolo();

        $objProcedimentoBD = new ProcedimentoBD(BancoSEI::getInstance());
        $arrObjProcedimentoDTO = $objProcedimentoBD->listar($objProcedimentoDTO);

        if (!empty($arrObjProcedimentoDTO)) {

            foreach ($arrObjProcedimentoDTO as $objProcedimentoDTO) {

                $dblIdProcedimento = $objProcedimentoDTO->getDblIdProcedimento();
                $objPenProtocoloDTO = new PenProtocoloDTO();
                $objPenProtocoloDTO->setDblIdProtocolo($dblIdProcedimento);
                $objPenProtocoloDTO->retStrSinObteveRecusa();
                $objPenProtocoloDTO->setNumMaxRegistrosRetorno(1);

                $objProtocoloBD = new ProtocoloBD(BancoSEI::getInstance());
                $objPenProtocoloDTO = $objProtocoloBD->consultar($objPenProtocoloDTO);

                if (!empty($objPenProtocoloDTO) && $objPenProtocoloDTO->getStrSinObteveRecusa() == 'S') {
                    $arrStrIcone[$dblIdProcedimento] = array('<img src="' . $this->getDiretorioImagens() . '/pen_tramite_recusado.png" title="Um tr�mite para esse processo foi recusado" />');
                }

            }
        }

        return $arrStrIcone;
    }

    public function montarIconeProcesso(ProcedimentoAPI $objProcedimentoAP) {
        $dblIdProcedimento = $objProcedimentoAP->getIdProcedimento();

        $objArvoreAcaoItemAPI = new ArvoreAcaoItemAPI();
        $objArvoreAcaoItemAPI->setTipo('MD_TRAMITE_PROCESSO');
        $objArvoreAcaoItemAPI->setId('MD_TRAMITE_PROC_' . $dblIdProcedimento);
        $objArvoreAcaoItemAPI->setIdPai($dblIdProcedimento);
        $objArvoreAcaoItemAPI->setTitle('Um tr�mite para esse processo foi recusado');
        $objArvoreAcaoItemAPI->setIcone($this->getDiretorioImagens() . '/pen_tramite_recusado.png');

        $objArvoreAcaoItemAPI->setTarget(null);
        $objArvoreAcaoItemAPI->setHref('javascript:alert(\'Um tr�mite para esse processo foi recusado\');');

        $objArvoreAcaoItemAPI->setSinHabilitado('S');

        $objProcedimentoDTO = new ProcedimentoDTO();
        $objProcedimentoDTO->setDblIdProcedimento($dblIdProcedimento);
        $objProcedimentoDTO->retDblIdProcedimento();
        $objProcedimentoDTO->retStrStaEstadoProtocolo();

        $objProcedimentoBD = new ProcedimentoBD(BancoSEI::getInstance());
        $arrObjProcedimentoDTO = $objProcedimentoBD->consultar($objProcedimentoDTO);

        if (!empty($arrObjProcedimentoDTO)) {
            $dblIdProcedimento = $objProcedimentoDTO->getDblIdProcedimento();
            $objPenProtocoloDTO = new PenProtocoloDTO();
            $objPenProtocoloDTO->setDblIdProtocolo($dblIdProcedimento);
            $objPenProtocoloDTO->retStrSinObteveRecusa();
            $objPenProtocoloDTO->setNumMaxRegistrosRetorno(1);

            $objProtocoloBD = new ProtocoloBD(BancoSEI::getInstance());
            $objPenProtocoloDTO = $objProtocoloBD->consultar($objPenProtocoloDTO);

            if (!empty($objPenProtocoloDTO) && $objPenProtocoloDTO->getStrSinObteveRecusa() == 'S') {
                $arrObjArvoreAcaoItemAPI[] = $objArvoreAcaoItemAPI;
            }
        } else {
            return array();
        }

        return $arrObjArvoreAcaoItemAPI;
    }

    public function montarIconeAcompanhamentoEspecial($arrObjProcedimentoDTO) {

    }

    public function getDiretorioImagens() {
        return static::getDiretorio() . '/imagens';
    }

    public function montarMensagemProcesso(ProcedimentoAPI $objProcedimentoAPI) {

        $objExpedirProcedimentoRN = new ExpedirProcedimentoRN();
        $objAtividadeDTO = $objExpedirProcedimentoRN->verificarProcessoEmExpedicao($objProcedimentoAPI->getIdProcedimento());

        if ($objAtividadeDTO && $objAtividadeDTO->getNumIdTarefa() == ProcessoEletronicoRN::obterIdTarefaModulo(ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_EXPEDIDO)) {

            $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
            $objAtributoAndamentoDTO->setStrNome('UNIDADE_DESTINO');
            $objAtributoAndamentoDTO->setNumIdAtividade($objAtividadeDTO->getNumIdAtividade());
            $objAtributoAndamentoDTO->retStrValor();

            $objAtributoAndamentoRN = new AtributoAndamentoRN();
            $objAtributoAndamentoDTO = $objAtributoAndamentoRN->consultarRN1366($objAtributoAndamentoDTO);

            return sprintf('Processo em tr�mite externo para "%s".', $objAtributoAndamentoDTO->getStrValor());
        }
    }

    public static function getDiretorio() {
        $arrConfig = ConfiguracaoSEI::getInstance()->getValor('SEI', 'Modulos');
        $strModulo = $arrConfig['PENIntegracao'];
        return "modulos/".$strModulo;
    }

    public function processarControlador($strAcao)
    {
        //Configura��o de p�ginas do contexto da �rvore do processo para apresenta��o de erro de forma correta
        $bolArvore = in_array($strAcao, array('pen_procedimento_expedir', 'pen_procedimento_estado'));
        PaginaSEI::getInstance()->setBolArvore($bolArvore);

        if (strpos($strAcao, 'pen_') === false) {
            return false;
        }

        PENIntegracao::validarCompatibilidadeModulo();

        switch ($strAcao) {
            case 'pen_procedimento_expedir':
            require_once dirname(__FILE__) . '/pen_procedimento_expedir.php';
            break;

            case 'pen_unidade_sel_expedir_procedimento':
            require_once dirname(__FILE__) . '/pen_unidade_sel_expedir_procedimento.php';
            break;

            case 'pen_procedimento_processo_anexado':
            require_once dirname(__FILE__) . '/pen_procedimento_processo_anexado.php';
            break;

            case 'pen_procedimento_cancelar_expedir':
            require_once dirname(__FILE__) . '/pen_procedimento_cancelar_expedir.php';
            break;

            case 'pen_procedimento_expedido_listar':
            require_once dirname(__FILE__) . '/pen_procedimento_expedido_listar.php';
            break;

            case 'pen_map_tipo_documento_envio_listar':
            case 'pen_map_tipo_documento_envio_excluir':
            case 'pen_map_tipo_documento_envio_desativar':
            case 'pen_map_tipo_documento_envio_ativar':
            require_once dirname(__FILE__) . '/pen_map_tipo_documento_envio_listar.php';
            break;

            case 'pen_map_tipo_documento_envio_cadastrar':
            case 'pen_map_tipo_documento_envio_visualizar':
            require_once dirname(__FILE__) . '/pen_map_tipo_documento_envio_cadastrar.php';
            break;

            case 'pen_map_tipo_documento_recebimento_listar':
            case 'pen_map_tipo_documento_recebimento_excluir':
            require_once dirname(__FILE__) . '/pen_map_tipo_documento_recebimento_listar.php';
            break;

            case 'pen_map_tipo_documento_recebimento_cadastrar':
            case 'pen_map_tipo_documento_recebimento_visualizar':
            require_once dirname(__FILE__) . '/pen_map_tipo_documento_recebimento_cadastrar.php';
            break;

            case 'pen_apensados_selecionar_expedir_procedimento':
            require_once dirname(__FILE__) . '/apensados_selecionar_expedir_procedimento.php';
            break;

            case 'pen_unidades_administrativas_externas_selecionar_expedir_procedimento':
                //verifica qual o tipo de sele��o passado para carregar o arquivo especifico.
            if($_GET['tipo_pesquisa'] == 1){
                require_once dirname(__FILE__) . '/pen_unidades_administrativas_selecionar_expedir_procedimento.php';
            }else {
                require_once dirname(__FILE__) . '/pen_unidades_administrativas_pesquisa_textual_expedir_procedimento.php';
            }
            break;

            case 'pen_procedimento_estado':
            require_once dirname(__FILE__) . '/pen_procedimento_estado.php';
            break;

            // Mapeamento de Hip�teses Legais de Envio
            case 'pen_map_hipotese_legal_envio_cadastrar':
            case 'pen_map_hipotese_legal_envio_visualizar':
            require_once dirname(__FILE__) . '/pen_map_hipotese_legal_envio_cadastrar.php';
            break;

            case 'pen_map_hipotese_legal_envio_listar':
            case 'pen_map_hipotese_legal_envio_excluir':
            require_once dirname(__FILE__) . '/pen_map_hipotese_legal_envio_listar.php';
            break;

            // Mapeamento de Hip�teses Legais de Recebimento
            case 'pen_map_hipotese_legal_recebimento_cadastrar':
            case 'pen_map_hipotese_legal_recebimento_visualizar':
            require_once dirname(__FILE__) . '/pen_map_hipotese_legal_recebimento_cadastrar.php';
            break;

            case 'pen_map_hipotese_legal_recebimento_listar':
            case 'pen_map_hipotese_legal_recebimento_excluir':
            require_once dirname(__FILE__) . '/pen_map_hipotese_legal_recebimento_listar.php';
            break;

            case 'pen_map_hipotese_legal_padrao_cadastrar':
            case 'pen_map_hipotese_legal_padrao_visualizar':
            require_once dirname(__FILE__) . '/pen_map_hipotese_legal_padrao_cadastrar.php';
            break;

            case 'pen_map_unidade_cadastrar':
            case 'pen_map_unidade_visualizar':
            require_once dirname(__FILE__) . '/pen_map_unidade_cadastrar.php';
            break;

            case 'pen_map_unidade_listar':
            case 'pen_map_unidade_excluir':
            require_once dirname(__FILE__) . '/pen_map_unidade_listar.php';
            break;

            case 'pen_parametros_configuracao':
            case 'pen_parametros_configuracao_salvar':
            require_once dirname(__FILE__) . '/pen_parametros_configuracao.php';
            break;
            default:
            return false;
            break;
        }

        return true;
    }

    public function processarControladorAjax($strAcao) {
        $xml = null;

        switch ($_GET['acao_ajax']) {

            case 'pen_unidade_auto_completar_expedir_procedimento':
            $arrObjEstruturaDTO = (array) ProcessoEletronicoINT::autoCompletarEstruturas($_POST['id_repositorio'], $_POST['palavras_pesquisa']);

            if (count($arrObjEstruturaDTO) > 0) {
                $xml = InfraAjax::gerarXMLItensArrInfraDTO($arrObjEstruturaDTO, 'NumeroDeIdentificacaoDaEstrutura', 'Nome');
            } else {
                return '<itens><item id="0" descricao="Unidade n�o Encontrada."></item></itens>';
            }
            break;

            case 'pen_apensados_auto_completar_expedir_procedimento':
                //TODO: Validar par�metros passados via ajax
            $dblIdProcedimentoAtual = $_POST['id_procedimento_atual'];
            $numIdUnidadeAtual = SessaoSEI::getInstance()->getNumIdUnidadeAtual();
            $arrObjProcedimentoDTO = ProcessoEletronicoINT::autoCompletarProcessosApensados($dblIdProcedimentoAtual, $numIdUnidadeAtual, $_POST['palavras_pesquisa']);
            $xml = InfraAjax::gerarXMLItensArrInfraDTO($arrObjProcedimentoDTO, 'IdProtocolo', 'ProtocoloFormatadoProtocolo');
            break;


            case 'pen_procedimento_expedir_validar':
            require_once dirname(__FILE__) . '/pen_procedimento_expedir_validar.php';
            break;


            case 'pen_pesquisar_unidades_administrativas_estrutura_pai':
            $idRepositorioEstruturaOrganizacional = $_POST['idRepositorioEstruturaOrganizacional'];
            $numeroDeIdentificacaoDaEstrutura = $_POST['numeroDeIdentificacaoDaEstrutura'];

            $objProcessoEletronicoRN = new ProcessoEletronicoRN();
            $arrEstruturas = $objProcessoEletronicoRN->consultarEstruturasPorEstruturaPai($idRepositorioEstruturaOrganizacional, $numeroDeIdentificacaoDaEstrutura == "" ? null : $numeroDeIdentificacaoDaEstrutura);

            // Obten��o da hierarquia de siglas desativada por quest�es de desempenho
            //$arrEstruturas = $this->obterHierarquiaEstruturaDeUnidadeExterna($idRepositorioEstruturaOrganizacional, $arrEstruturas);

            print json_encode($arrEstruturas);
            exit(0);
            break;


            case 'pen_pesquisar_unidades_administrativas_estrutura_pai_textual':
            $registrosPorPagina = 50;
            $idRepositorioEstruturaOrganizacional = $_POST['idRepositorioEstruturaOrganizacional'];
            $numeroDeIdentificacaoDaEstrutura     = $_POST['numeroDeIdentificacaoDaEstrutura'];
            $siglaUnidade = ($_POST['siglaUnidade'] == '') ? null : utf8_encode($_POST['siglaUnidade']);
            $nomeUnidade  = ($_POST['nomeUnidade']  == '') ? null : utf8_encode($_POST['nomeUnidade']);
            $offset       = $_POST['offset'] * $registrosPorPagina;

            $objProcessoEletronicoRN = new ProcessoEletronicoRN();
            //print "Texto: " . $numeroDeIdentificacaoDaEstrutura;
            //$siglaUnidade = 'CGPRO';
            $arrObjEstruturaDTO = $objProcessoEletronicoRN->listarEstruturas($idRepositorioEstruturaOrganizacional, null, $numeroDeIdentificacaoDaEstrutura, $nomeUnidade, $siglaUnidade, $offset, $registrosPorPagina);

            $interface = new ProcessoEletronicoINT();
                //Gera a hierarquia de SIGLAS das estruturas
            $arrHierarquiaEstruturaDTO = $interface->gerarHierarquiaEstruturas($arrObjEstruturaDTO);

            $arrEstruturas['estrutura'] = [];
            if(!is_null($arrHierarquiaEstruturaDTO[0])){
                foreach ($arrHierarquiaEstruturaDTO as $key => $estrutura) {
                        //Monta um array com as estruturas para retornar o JSON
                    $arrEstruturas['estrutura'][$key]['nome'] = utf8_encode($estrutura->get('Nome'));
                    $arrEstruturas['estrutura'][$key]['numeroDeIdentificacaoDaEstrutura'] = $estrutura->get('NumeroDeIdentificacaoDaEstrutura');
                    $arrEstruturas['estrutura'][$key]['sigla'] = utf8_encode($estrutura->get('Sigla'));
                    $arrEstruturas['estrutura'][$key]['ativo'] = $estrutura->get('Ativo');
                    $arrEstruturas['estrutura'][$key]['aptoParaReceberTramites'] = $estrutura->get('AptoParaReceberTramites');
                    $arrEstruturas['estrutura'][$key]['codigoNoOrgaoEntidade'] = $estrutura->get('CodigoNoOrgaoEntidade');

                }
                $arrEstruturas['totalDeRegistros']   = $estrutura->get('TotalDeRegistros');
                $arrEstruturas['registrosPorPagina'] = $registrosPorPagina;
            }

            print json_encode($arrEstruturas);
            exit(0);
            break;
        }

        return $xml;
    }

    public static function validarCompatibilidadeModulo($parStrVersaoSEI=null)
    {
        $strVersaoSEI =  $parStrVersaoSEI ?: SEI_VERSAO;
        $objPENIntegracao = new PENIntegracao();
        if(!in_array($strVersaoSEI, self::COMPATIBILIDADE_MODULO_SEI)) {
            throw new InfraException(sprintf("M�dulo %s (vers�o %s) n�o � compat�vel com a vers�o %s do SEI.", $objPENIntegracao->getNome(), $objPENIntegracao->getVersao(), $strVersaoSEI));
        }
    }

     /**
      * M�todo respons�vel por recuperar a hierarquia da unidade e montar o seu nome com as SIGLAS da hierarquia
      * @author Josinaldo J<FA>nior <josinaldo.junior@basis.com.br>
      * @param $idRepositorioEstruturaOrganizacional
      * @param $arrEstruturas
      * @return mixed
      * @throws InfraException
      */
     private function obterHierarquiaEstruturaDeUnidadeExterna($idRepositorioEstruturaOrganizacional, $arrEstruturas)
     {
        //Monta o nome da unidade com a hierarquia de SIGLAS
        $objProcessoEletronicoRN = new ProcessoEletronicoRN();
        foreach ($arrEstruturas as $key => $estrutura) {
            if(!is_null($estrutura)) {
                $arrObjEstruturaDTO = $objProcessoEletronicoRN->listarEstruturas($idRepositorioEstruturaOrganizacional, $estrutura->numeroDeIdentificacaoDaEstrutura);
                if (!is_null($arrObjEstruturaDTO[0])) {
                    $interface = new ProcessoEletronicoINT();
                    $arrHierarquiaEstruturaDTO = $interface->gerarHierarquiaEstruturas($arrObjEstruturaDTO);
                    $arrEstruturas[$key]->nome = utf8_encode($arrHierarquiaEstruturaDTO[0]->get('Nome'));
                }
            }
        }

        return $arrEstruturas;
    }


    /**
     * M�todo respons�vel pela valida��o da compatibilidade do banco de dados do m�dulo em rela��o ao vers�o instalada.
     *
     * @param  boolean $bolGerarExcecao Flag para gera��o de exce��o do tipo InfraException caso base de dados incompat�vel
     * @return boolean                  Indicardor se base de dados � compat�vel
     */
    public static function validarCompatibilidadeBanco($bolGerarExcecao=true)
    {
        $objInfraParametro = new InfraParametro(BancoSEI::getInstance());
        $strVersaoBancoModulo = $objInfraParametro->getValor(PenAtualizarSeiRN::PARAMETRO_VERSAO_MODULO, false) ?: $objInfraParametro->getValor(PenAtualizarSeiRN::PARAMETRO_VERSAO_MODULO_ANTIGO, false);

        $objPENIntegracao = new PENIntegracao();
        $strVersaoModulo = $objPENIntegracao->getVersao();

        $bolBaseCompativel = ($strVersaoModulo === $strVersaoBancoModulo);

        if(!$bolBaseCompativel && $bolGerarExcecao){
            throw new ModuloIncompativelException(sprintf("Base de dados do m�dulo '%s' (vers�o %s) encontra-se incompat�vel. A vers�o da base de dados atualmente instalada � a %s. \n ".
                "Favor entrar em contato com o administrador do sistema.", $objPENIntegracao->getNome(), $strVersaoModulo, $strVersaoBancoModulo));
        }

        return $bolBaseCompativel;
    }

}

class ModuloIncompativelException extends InfraException { }
