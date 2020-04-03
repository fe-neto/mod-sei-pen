<?php

require_once dirname(__FILE__) . '/../../../SEI.php';

class ReceberProcedimentoRN extends InfraRN
{
    const STR_APENSACAO_PROCEDIMENTOS = 'Relacionamento representando a apensa��o de processos recebidos externamente';
    
    private $objProcessoEletronicoRN;
    private $objInfraParametro;
    private $objProcedimentoAndamentoRN;
    private $documentosRetirados = array();
    public $destinatarioReal = null;
    private $objPenDebug = null;
    
    public function __construct()
    {
        parent::__construct();
        $this->objInfraParametro = new InfraParametro(BancoSEI::getInstance());
        $this->objProcessoEletronicoRN = new ProcessoEletronicoRN();
        $this->objProcedimentoAndamentoRN = new ProcedimentoAndamentoRN();
        $this->objReceberComponenteDigitalRN = new ReceberComponenteDigitalRN();
        $this->objPenDebug = DebugPen::getInstance();
    }
    
    protected function inicializarObjInfraIBanco()
    {
        return BancoSEI::getInstance();
    }
    
    protected function listarPendenciasConectado()
    {
        $arrObjPendencias = $this->objProcessoEletronicoRN->listarPendencias(true);
        return $arrObjPendencias;
    }
    
    protected function receberProcedimentoControlado($parNumIdentificacaoTramite)
    {
        try {
            $objPenParametroRN = new PenParametroRN();
            
            // O recebimento do processo deve ser realizado na unidade definida em [UNIDADE_GERADORA_DOCUMENTO_RECEBIDO] que n�o dever� possuir usu�rios
            // habilitados, funcionando como uma �rea dedicada unicamente para o recebimento de processos e documentos.
            // Isto � necess�rio para que o processo recebido n�o seja criado diretamente dentro da unidade de destino, o que permitiria a altera��o de
            // todos os metadados do processo, comportamento n�o permitido pelas regras de neg�cio do PEN.
            SessaoSEI::getInstance(false)->simularLogin('SEI', null, null, $objPenParametroRN->getParametro('PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO'));
            
            $objSeiRN = new SeiRN();
            if (!isset($parNumIdentificacaoTramite)) {
                throw new InfraException('Par�metro $parNumIdentificacaoTramite n�o informado.');
            }
            
            $this->gravarLogDebug("Solicitando metadados do tr�mite " . $parNumIdentificacaoTramite, 3);
            $objMetadadosProcedimento = $this->objProcessoEletronicoRN->solicitarMetadados($parNumIdentificacaoTramite);
            if (!isset($objMetadadosProcedimento)) {
                throw new InfraException("Metadados do tr�mite n�o pode recuperado do PEN.");
            }
            
            $strNumeroRegistro = $objMetadadosProcedimento->metadados->NRE;
            $objProtocolo = ProcessoEletronicoRN::obterProtocoloDosMetadados($objMetadadosProcedimento);
            
            $bolEhProcesso = $objProtocolo->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_PROCESSO;
            $strIdTarefa = $bolEhProcesso ? ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_RECEBIDO : ProcessoEletronicoRN::$TI_DOCUMENTO_AVULSO_RECEBIDO;
            $numIdTarefa = ProcessoEletronicoRN::obterIdTarefaModulo($strIdTarefa);
            $this->objProcedimentoAndamentoRN->setOpts($strNumeroRegistro, $parNumIdentificacaoTramite, $numIdTarefa);
            $this->objProcedimentoAndamentoRN->cadastrar(ProcedimentoAndamentoDTO::criarAndamento('Iniciando recebimento de processo externo', 'S'));
            
            //Verifica se processo j� foi registrado para esse tr�mite
            //Tratamento para evitar o recebimento simult�neo do mesmo procedimento em servi�os/processos concorrentes
            $this->sincronizarRecebimentoProcessos($strNumeroRegistro, $parNumIdentificacaoTramite, $numIdTarefa);
            if($this->tramiteRecebimentoRegistrado($strNumeroRegistro, $parNumIdentificacaoTramite)) {
                $this->gravarLogDebug("Tr�mite de recebimento $parNumIdentificacaoTramite j� registrado para o processo " . $objProtocolo->protocolo, 3);
                return;
            }
            
            //Substituir a unidade destinat�ria pela unidade centralizadora definida pelo Gestor de Protocolo no PEN
            $this->substituirDestinoParaUnidadeReceptora($objMetadadosProcedimento, $parNumIdentificacaoTramite);
            
            // Valida��o dos dados do processo recebido
            $objInfraException = new InfraException();
            $this->validarDadosDestinatario($objInfraException, $objMetadadosProcedimento);
            $objInfraException->lancarValidacoes();
            
            ############################# INICIA O RECEBIMENTO DOS COMPONENTES DIGITAIS US010 ################################################
            $arrObjTramite = $this->objProcessoEletronicoRN->consultarTramites($parNumIdentificacaoTramite);
            $objTramite = $arrObjTramite[0];
            
            $this->gravarLogDebug("Obt�m lista de componentes digitais que precisam ser baixados", 3);
            if(!is_array($objTramite->componenteDigitalPendenteDeRecebimento)){
                $objTramite->componenteDigitalPendenteDeRecebimento = array($objTramite->componenteDigitalPendenteDeRecebimento);
            }
            
            $this->validarComponentesDigitais($objProtocolo, $parNumIdentificacaoTramite);
            $this->validarExtensaoComponentesDigitais($parNumIdentificacaoTramite, $objProtocolo);
            $this->verificarPermissoesDiretorios($parNumIdentificacaoTramite);
            
            $arrayHash = array();
            $arrAnexosComponentes = array();
            $receberComponenteDigitalRN = new ReceberComponenteDigitalRN();
            $this->gravarLogDebug("Obtendo metadados dos componentes digitais do processo", 3);
            
            // TODO: Refatorar c�digo abaixo, encapsulando um nova fun��o para realizar o download dos componentes
            // $this->baixarComponentesDigitais(...)
            
            // Lista todos os componentes digitais presente no protocolo
            // Esta verifica��o � necess�ria pois existem situa��es em que a lista de componentes
            // pendentes de recebimento informado pelo PEN n�o est� de acordo com a lista atual de arquivos
            // mantida pela aplica��o.
            $arrHashComponentesProtocolo = $this->listarHashDosComponentesMetadado($objProtocolo);
            $arrHashPendentesDownload = $objTramite->componenteDigitalPendenteDeRecebimento;
            
            $numQtdComponentes = count($arrHashComponentesProtocolo);
            $this->gravarLogDebug("$numQtdComponentes componentes digitais identificados no protocolo {$objProtocolo->protocolo}", 5);
            
            // Percorre os componentes que precisam ser recebidos
            $arrayObjAnexoBaixadoPraPastaTemp = array();
            foreach($arrHashComponentesProtocolo as $key => $componentePendente){
                
                $numOrdemComponente = $key + 1;
                if(!is_null($componentePendente)) {
                    //Download do componente digital � realizado, mesmo j� existindo na base de dados, devido a comportamento obrigat�rio do Barramento para mudan�a de status
                    //Ajuste dever� ser feito em vers�es futuras
                    $arrayHash[] = $componentePendente;
                    $nrTamanhoBytesArquivo = $this->obterTamanhoComponenteDigitalPendente($objProtocolo, $componentePendente);
                    $nrTamanhoArquivoKB = round($nrTamanhoBytesArquivo / 1024, 2);
                    $nrTamanhoMegasMaximo  = $objPenParametroRN->getParametro('PEN_TAMANHO_MAXIMO_DOCUMENTO_EXPEDIDO');
                    $nrTamanhoBytesMaximo  = $nrTamanhoMegasMaximo * pow(1024, 2);
                    
                    $arrObjComponenteDigitalIndexado = self::indexarComponenteDigitaisDoProtocolo($objProtocolo);
                    $nrTamanhoBytesArquivo = $this->obterTamanhoComponenteDigitalPendente($objProtocolo, $componentePendente);
                    
                    //Obter os dados do componente digital particionado
                    $this->gravarLogDebug("Baixando componente digital $numOrdemComponente particionado", 5);
                    $objAnexoDTO = $this->receberComponenenteDigitalParticionado(
                        $componentePendente, $nrTamanhoBytesMaximo, $nrTamanhoBytesArquivo, $nrTamanhoMegasMaximo,
                        $numOrdemComponente, $parNumIdentificacaoTramite, $objTramite, $arrObjComponenteDigitalIndexado
                    );
                    $arrAnexosComponentes[$key][$componentePendente] = $objAnexoDTO;
                    
                    $objAnexoBaixadoPraPastaTemp = $arrAnexosComponentes[$key][$componentePendente];
                    $objAnexoBaixadoPraPastaTemp->hash = $componentePendente;
                    array_push($arrayObjAnexoBaixadoPraPastaTemp, $objAnexoBaixadoPraPastaTemp);
                    
                    //Valida a integridade do componente via hash
                    $this->gravarLogDebug("Validando integridade de componente digital $numOrdemComponente", 6);
                    $numTempoInicialValidacao = microtime(true);
                    $this->objReceberComponenteDigitalRN->validarIntegridadeDoComponenteDigital(
                        $arrAnexosComponentes[$key][$componentePendente], $componentePendente, $parNumIdentificacaoTramite, $numOrdemComponente
                    );
                    $numTempoTotalValidacao = round(microtime(true) - $numTempoInicialValidacao, 2);
                    $numVelocidade = round($nrTamanhoArquivoKB / max([$numTempoTotalValidacao, 1]), 2);
                    $this->gravarLogDebug("Tempo total de valida��o de integridade: {$numTempoTotalValidacao}s ({$numVelocidade} kb/s)", 6);
                }
            }
            
            if(count($arrAnexosComponentes) > 0){
                $this->objReceberComponenteDigitalRN->setArrAnexos($arrAnexosComponentes);
            }
            
            #############################TERMINA O RECEBIMENTO DOS COMPONENTES DIGITAIS US010################################################
            $arrObjTramite = $this->objProcessoEletronicoRN->consultarTramites($parNumIdentificacaoTramite);
            $objTramite = $arrObjTramite[0];
            

            //Verifica se o tr�mite est� recusado
            //TODO: Testar o erro de interrup��o for�ado para certificar que o rollback est� sendo realizado da forma correta
            if($objTramite->situacaoAtual == ProcessoEletronicoRN::$STA_SITUACAO_TRAMITE_RECUSADO) {
                throw new InfraException("Tr�mite $parNumIdentificacaoTramite j� se encontra recusado. Cancelando o recebimento do processo");
            }
            
            //TODO: processo-anexado
            if($objProtocolo->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_PROCESSO){
                //$objProcessoPrincipal = $this->desmembrarProcessosAnexados($objProtocolo);
                $objProtocolo = $this->desmembrarProcessosAnexados($objProtocolo);
            }            
            
            $this->gravarLogDebug("Persistindo/atualizando dados do processo com NRE " . $strNumeroRegistro, 3);            
            list($objProcedimentoDTO, $bolProcedimentoExistente) = $this->registrarProcesso(
                $strNumeroRegistro,
                $parNumIdentificacaoTramite,
                $objProtocolo,
                $objMetadadosProcedimento
            );
            
            $this->objProcedimentoAndamentoRN->setOpts($strNumeroRegistro, $parNumIdentificacaoTramite, ProcessoEletronicoRN::obterIdTarefaModulo(ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_RECEBIDO), $objProcedimentoDTO->getDblIdProcedimento());
            $this->objProcedimentoAndamentoRN->cadastrar(ProcedimentoAndamentoDTO::criarAndamento('Obtendo metadados do processo', 'S'));
            
            $this->gravarLogDebug("Registrando tr�mite externo do processo", 3);
            $objProcessoEletronicoDTO = $this->objProcessoEletronicoRN->cadastrarTramiteDeProcesso(
                $objProcedimentoDTO->getDblIdProcedimento(),
                $strNumeroRegistro,
                $parNumIdentificacaoTramite,
                ProcessoEletronicoRN::$STA_TIPO_TRAMITE_RECEBIMENTO,
                null,
                $objMetadadosProcedimento->metadados->remetente->identificacaoDoRepositorioDeEstruturas,
                $objMetadadosProcedimento->metadados->remetente->numeroDeIdentificacaoDaEstrutura,
                $objMetadadosProcedimento->metadados->destinatario->identificacaoDoRepositorioDeEstruturas,
                $objMetadadosProcedimento->metadados->destinatario->numeroDeIdentificacaoDaEstrutura,
                $objProtocolo
            );
            
            //Verifica se o tramite se encontra na situa��o correta
            $arrObjTramite = $this->objProcessoEletronicoRN->consultarTramites($parNumIdentificacaoTramite);
            if(!isset($arrObjTramite) || count($arrObjTramite) != 1) {
                throw new InfraException("Tr�mite n�o pode ser localizado pelo identificado $parNumIdentificacaoTramite.");
            }
            
            $objTramite = $arrObjTramite[0];
            if($objTramite->situacaoAtual != ProcessoEletronicoRN::$STA_SITUACAO_TRAMITE_COMPONENTES_RECEBIDOS_DESTINATARIO) {
                throw new InfraException("Desconsiderando recebimento do processo devido a situa��o de tr�mite inconsistente: " . $objTramite->situacaoAtual);
            }
            
            //Atribui componentes digitais baixados anteriormente aos documentos do processo
            //TODO: processo-anexado - Necess�rio atribuir os documentos nos processos correspondentes
            $this->atribuirComponentesDigitaisAosDocumentos($objProcedimentoDTO, $strNumeroRegistro, $parNumIdentificacaoTramite, $arrayHash, $objProtocolo);
            
            //Finalizar o envio do documento para a respectiva unidade
            $this->enviarProcedimentoUnidade($objProcedimentoDTO, null, $bolProcedimentoExistente);
            
            $this->validarPosCondicoesTramite($objMetadadosProcedimento->metadados, $objProcedimentoDTO);
            
            $this->gravarLogDebug("Enviando recibo de conclus�o do tr�mite $parNumIdentificacaoTramite", 5);
            $objEnviarReciboTramiteRN = new EnviarReciboTramiteRN();
            $objEnviarReciboTramiteRN->enviarReciboTramiteProcesso($parNumIdentificacaoTramite, $arrayHash);
            
            $this->gravarLogDebug("Registrando a conclus�o do recebimento do tr�mite $parNumIdentificacaoTramite", 5);
            $objPenTramiteProcessadoRN = new PenTramiteProcessadoRN(PenTramiteProcessadoRN::STR_TIPO_PROCESSO);
            $objPenTramiteProcessadoRN->setRecebido($parNumIdentificacaoTramite);
        } catch (Exception $e) {
            $mensagemErro = InfraException::inspecionar($e);
            $this->gravarLogDebug($mensagemErro);
            LogSEI::getInstance()->gravar($mensagemErro);
            throw $e;
        }
    }
    
    /**
    * M�todo respons�vel por atribuir a lista de componentes digitais baixados do PEN aos seus respectivos documentos no SEI
    */
    private function atribuirComponentesDigitaisAosDocumentos(ProcedimentoDTO $parObjProcedimentoDTO, $parStrNumeroRegistro, $parNumIdentificacaoTramite,
    $parArrHashComponentes, $objProtocolo)
    {
        if(count($parArrHashComponentes) > 0){
            //Obter dados dos componetes digitais
            $this->gravarLogDebug("Iniciando o armazenamento dos componentes digitais pendentes", 4);
            $objComponenteDigitalDTO = new ComponenteDigitalDTO();
            $objComponenteDigitalDTO->setStrNumeroRegistro($parStrNumeroRegistro);
            $objComponenteDigitalDTO->setNumIdTramite($parNumIdentificacaoTramite);
            $objComponenteDigitalDTO->setStrHashConteudo($parArrHashComponentes, InfraDTO::$OPER_IN);
            $objComponenteDigitalDTO->setOrdNumOrdem(InfraDTO::$TIPO_ORDENACAO_ASC);
            $objComponenteDigitalDTO->retDblIdProcedimento();
            $objComponenteDigitalDTO->retDblIdDocumento();
            $objComponenteDigitalDTO->retNumTicketEnvioComponentes();
            $objComponenteDigitalDTO->retStrProtocoloDocumentoFormatado();
            $objComponenteDigitalDTO->retStrHashConteudo();
            $objComponenteDigitalDTO->retStrProtocolo();
            $objComponenteDigitalDTO->retStrNumeroRegistro();
            $objComponenteDigitalDTO->retNumIdTramite();
            $objComponenteDigitalDTO->retStrNome();
            $objComponenteDigitalDTO->retStrStaEstadoProtocolo();
            
            $objComponenteDigitalBD = new ComponenteDigitalBD($this->getObjInfraIBanco());
            $arrObjComponentesDigitaisDTO = $objComponenteDigitalBD->listar($objComponenteDigitalDTO);
            
            if(!empty($arrObjComponentesDigitaisDTO)){
                $arrStrNomeDocumento = $this->listarMetaDadosComponentesDigitais($objProtocolo);
                $arrCompenentesDigitaisIndexados = InfraArray::indexarArrInfraDTO($arrObjComponentesDigitaisDTO, 'IdDocumento', true);
                
                foreach ($arrCompenentesDigitaisIndexados as $numIdDocumento => $arrObjComponenteDigitalDTO){
                    if(!empty($arrObjComponenteDigitalDTO)){
                        
                        foreach ($arrObjComponenteDigitalDTO as $objComponenteDigitalDTO) {
                            $dblIdProcedimento = $objComponenteDigitalDTO->getDblIdProcedimento();
                            $dblIdDocumento = $numIdDocumento;
                            $strHash = $objComponenteDigitalDTO->getStrHashConteudo();
                            
                            //Verificar se documento j� foi recebido anteriormente para poder registrar
                            if($this->documentosPendenteRegistro($dblIdProcedimento, $dblIdDocumento, $strHash)){
                                $this->objReceberComponenteDigitalRN->atribuirComponentesDigitaisAoDocumento($numIdDocumento, $arrObjComponenteDigitalDTO);
                                $strMensagemRecebimento = sprintf('Armazenando componente do documento %s', $objComponenteDigitalDTO->getStrProtocoloDocumentoFormatado());
                                $this->objProcedimentoAndamentoRN->cadastrar(ProcedimentoAndamentoDTO::criarAndamento($strMensagemRecebimento, 'S'));
                                $this->gravarLogDebug($strMensagemRecebimento, 6);
                            }
                        }
                    }
                }
                
                $this->objProcedimentoAndamentoRN->cadastrar(ProcedimentoAndamentoDTO::criarAndamento('Todos os componentes digitais foram recebidos', 'S'));
            }else{
                $this->objProcedimentoAndamentoRN->cadastrar(ProcedimentoAndamentoDTO::criarAndamento('Nenhum componente digital para receber', 'S'));
            }
        }
    }
    
    /**
    * M�todo para recuperar a lista de todos os hashs dos componentes digitais presentes no protocolo recebido
    *
    * @return Array Lista de hashs dos componentes digitais
    */
    private function listarHashDosComponentesMetadado($parObjProtocolo)
    {
        $arrHashsComponentesDigitais = array();
        $arrObjDocumento = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        foreach($arrObjDocumento as $objDocumento){
            
            //Desconsidera os componendes digitais de documentos cancelados
            if(!isset($objDocumento->retirado) || $objDocumento->retirado == false) {
                if(!isset($objDocumento->componenteDigital)){
                    throw new InfraException("Metadados do componente digital do documento de ordem {$objDocumento->ordem} n�o informado.");
                }
                
                $arrObjComponentesDigitais = is_array($objDocumento->componenteDigital) ? $objDocumento->componenteDigital : array($objDocumento->componenteDigital);
                foreach ($arrObjComponentesDigitais as $objComponenteDigital) {
                    $arrHashsComponentesDigitais[] = ProcessoEletronicoRN::getHashFromMetaDados($objComponenteDigital->hash);
                }
            }
        }
        
        return $arrHashsComponentesDigitais;
    }
    
    public function fecharProcedimentoEmOutraUnidades(ProcedimentoDTO $objProcedimentoDTO, $parObjMetadadosProcedimento){
        
        $objPenUnidadeDTO = new PenUnidadeDTO();
        $objPenUnidadeDTO->setNumIdUnidadeRH($parObjMetadadosProcedimento->metadados->destinatario->numeroDeIdentificacaoDaEstrutura);
        $objPenUnidadeDTO->retNumIdUnidade();
        
        $objGenericoBD = new GenericoBD($this->inicializarObjInfraIBanco());
        $objPenUnidadeDTO = $objGenericoBD->consultar($objPenUnidadeDTO);
        
        if(empty($objPenUnidadeDTO)) {
            return false;
        }
        
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDistinct(true);
        $objAtividadeDTO->setNumIdUnidade($objPenUnidadeDTO->getNumIdUnidade(), InfraDTO::$OPER_DIFERENTE);
        $objAtividadeDTO->setDblIdProtocolo($objProcedimentoDTO->getDblIdProcedimento());
        $objAtividadeDTO->setDthConclusao(null);
        $objAtividadeDTO->setOrdStrSiglaUnidade(InfraDTO::$TIPO_ORDENACAO_ASC);
        $objAtividadeDTO->setOrdStrSiglaUsuarioAtribuicao(InfraDTO::$TIPO_ORDENACAO_DESC);
        $objAtividadeDTO->retStrSiglaUnidade();
        $objAtividadeDTO->retStrDescricaoUnidade();
        $objAtividadeDTO->retNumIdUsuarioAtribuicao();
        $objAtividadeDTO->retStrSiglaUsuarioAtribuicao();
        $objAtividadeDTO->retStrNomeUsuarioAtribuicao();
        $objAtividadeDTO->retNumIdUnidade();
        
        $objAtividadeRN = new AtividadeRN();
        $arrObjAtividadeDTO = (array)$objAtividadeRN->listarRN0036($objAtividadeDTO);
        
        $objInfraSessao = SessaoSEI::getInstance();
        $numIdUnidade = $objInfraSessao->getNumIdUnidadeAtual();
        
        foreach($arrObjAtividadeDTO as $objAtividadeDTO) {
            
            $objInfraSessao->setNumIdUnidadeAtual($objAtividadeDTO->getNumIdUnidade());
            $objInfraSessao->trocarUnidadeAtual();
            
            $objProcedimentoRN = new ProcedimentoRN();
            $objProcedimentoRN->concluir(array($objProcedimentoDTO));
        }
        $objInfraSessao->setNumIdUnidadeAtual($numIdUnidade);
        $objInfraSessao->trocarUnidadeAtual();
    }
    
    /**
    * Retorna um array com alguns metadados, onde o indice de � o hash do arquivo
    *
    * @return array[String]
    */
    private function listarMetaDadosComponentesDigitais($parObjProtocolo)
    {
        $arrMetadadoDocumento = array();
        $objMapBD = new GenericoBD($this->getObjInfraIBanco());
        
        $arrObjDocumento = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        foreach($arrObjDocumento as $objDocumento){
            $strHash = ProcessoEletronicoRN::getHashFromMetaDados($objDocumento->componenteDigital->hash);
            $objMapDTO = new PenRelTipoDocMapRecebidoDTO(true);
            $objMapDTO->setNumMaxRegistrosRetorno(1);
            $objMapDTO->setNumCodigoEspecie($objDocumento->especie->codigo);
            $objMapDTO->retStrNomeSerie();
            
            $objMapDTO = $objMapBD->consultar($objMapDTO);
            
            if(empty($objMapDTO)) {
                $strNomeDocumento = '[ref '.$objDocumento->especie->nomeNoProdutor.']';
            }
            else {
                $strNomeDocumento = $objMapDTO->getStrNomeSerie();
            }
            
            $arrMetadadoDocumento[$strHash] = array(
                'especieNome' => $strNomeDocumento
            );
        }
        
        return $arrMetadadoDocumento;
    }
    
    private function validarDadosProcesso(InfraException $objInfraException, $objMetadadosProcedimento)
    {
        
    }
    
    private function validarDadosDocumentos(InfraException $objInfraException, $objMetadadosProcedimento)
    {
        
    }
    
    /**
    * Valida cada componente digital, se n�o algum n�o for aceito recusa o tramite
    * do procedimento para esta unidade
    */
    private function validarComponentesDigitais($parObjProtocolo, $parNumIdentificacaoTramite)
    {
        $arrObjDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        
        foreach($arrObjDocumentos as $objDocument){
            
            $objPenRelTipoDocMapEnviadoDTO = new PenRelTipoDocMapRecebidoDTO();
            $objPenRelTipoDocMapEnviadoDTO->retTodos();
            $objPenRelTipoDocMapEnviadoDTO->setNumCodigoEspecie($objDocument->especie->codigo);
            
            $objProcessoEletronicoDB = new PenRelTipoDocMapRecebidoBD(BancoSEI::getInstance());
            $numContador = (integer)$objProcessoEletronicoDB->contar($objPenRelTipoDocMapEnviadoDTO);
            
            // N�o achou, ou seja, n�o esta cadastrado na tabela, ent�o n�o �
            // aceito nesta unidade como v�lido
            if($numContador <= 0) {
                $this->objProcessoEletronicoRN->recusarTramite($parNumIdentificacaoTramite, sprintf('Documento do tipo %s n�o est� mapeado', utf8_decode($objDocument->especie->nomeNoProdutor)), ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_ESPECIE_NAO_MAPEADA);
                throw new InfraException(sprintf('Documento do tipo %s n�o est� mapeado. Motivo da Recusa no Barramento: %s', $objDocument->especie->nomeNoProdutor, ProcessoEletronicoRN::$MOTIVOS_RECUSA[ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_ESPECIE_NAO_MAPEADA]));
            }
        }
        
        foreach($arrObjDocumentos as $objDocumento) {
            
            //N�o valida informa��es do componente digital caso o documento esteja cancelado
            if(isset($objDocument->retirado) && $objDocument->retirado === true){
                if (is_null($objDocument->componenteDigital->tamanhoEmBytes) || $objDocument->componenteDigital->tamanhoEmBytes == 0){
                    throw new InfraException('Tamanho de componente digital n�o informado.', null, 'RECUSA: '.ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_OUTROU);
                }
            }
        }
    }
    
    private function registrarProcesso($parStrNumeroRegistro, $parNumIdentificacaoTramite, $parObjProtocolo, $parObjMetadadosProcedimento)
    {
        // Valida��o dos dados do processo recebido
        $objInfraException = new InfraException();
        $this->validarDadosProcesso($objInfraException, $parObjProtocolo);
        $this->validarDadosDocumentos($objInfraException, $parObjProtocolo);
        
        // TODO: Regra de Neg�cio - Processos recebidos pelo Barramento n�o poder�o disponibilizar a op��o de reordena��o e cancelamento de documentos
        // para o usu�rio final, mesmo possuindo permiss�o para isso
        $objInfraException->lancarValidacoes();
        
        // Verificar se procedimento j� existia na base de dados do sistema
        $dblIdProcedimento = $this->consultarProcedimentoExistente($parStrNumeroRegistro, $parObjProtocolo->protocolo);
        $bolProcedimentoExistente = isset($dblIdProcedimento);
        
        if($bolProcedimentoExistente){
            $objProcedimentoDTO = $this->atualizarProcedimento($dblIdProcedimento, $parObjMetadadosProcedimento, $parObjProtocolo, $parNumIdentificacaoTramite);
        }
        else {
            $objProcedimentoDTO = $this->gerarProcedimento($parObjMetadadosProcedimento, $parObjProtocolo);
        }
        
        // Chamada recursiva para registro dos processos apensados
        if(isset($parObjProtocolo->processoApensado)) {
            if(!is_array($parObjProtocolo->processoApensado)) {
                $parObjProtocolo->processoApensado = array($parObjProtocolo->processoApensado);
            }
            
            foreach ($parObjProtocolo->processoApensado as $objProcessoApensado) {
                $this->registrarProcesso($parStrNumeroRegistro, $parNumIdentificacaoTramite, $objProcessoApensado, $parObjMetadadosProcedimento);
            }
        }        
        
        return array($objProcedimentoDTO, $bolProcedimentoExistente);
    }
    
    private function tramiteRecebimentoRegistrado($parStrNumeroRegistro, $parNumIdentificacaoTramite)
    {
        $objTramiteDTO = new TramiteDTO();
        $objTramiteDTO->setStrNumeroRegistro($parStrNumeroRegistro);
        $objTramiteDTO->setNumIdTramite($parNumIdentificacaoTramite);
        $objTramiteDTO->setStrStaTipoTramite(ProcessoEletronicoRN::$STA_TIPO_TRAMITE_RECEBIMENTO);
        $objTramiteBD = new TramiteBD($this->getObjInfraIBanco());
        return $objTramiteBD->contar($objTramiteDTO) > 0;
    }
    
    private function documentoJaRegistrado($parStrNumeroRegistro, $parNumIdentificacaoTramite, $parStrHashComponenteDigital)
    {
        //Verifica se componente digital j� est� registrado para o documento
        $objComponenteDigitalDTO = new ComponenteDigitalDTO();
        $objComponenteDigitalDTO->setStrNumeroRegistro($parStrNumeroRegistro);
        $objComponenteDigitalDTO->setNumIdTramite($parNumIdentificacaoTramite);
        $objComponenteDigitalDTO->setStrHashConteudo($parStrHashComponenteDigital);
        
        $objComponenteDigitalBD = new ComponenteDigitalBD($this->getObjInfraIBanco());
        return $objComponenteDigitalBD->contar($objComponenteDigitalDTO) > 0;
    }
    
    private function consultarProcedimentoExistente($parStrNumeroRegistro, $parStrProtocolo = null)
    {
        $dblIdProcedimento = null;
        $objProcessoEletronicoDTO = new ProcessoEletronicoDTO();
        $objProcessoEletronicoDTO->retDblIdProcedimento();
        $objProcessoEletronicoDTO->setStrNumeroRegistro($parStrNumeroRegistro);
        
        //TODO: Manter o padr�o o sistema em chamar uma classe de regra de neg�cio (RN) e n�o diretamente um classe BD
        $objProcessoEletronicoBD = new ProcessoEletronicoBD($this->getObjInfraIBanco());
        $objProcessoEletronicoDTO = $objProcessoEletronicoBD->consultar($objProcessoEletronicoDTO);
        
        if(isset($objProcessoEletronicoDTO)){
            $dblIdProcedimento = $objProcessoEletronicoDTO->getDblIdProcedimento();
        }
        
        return $dblIdProcedimento;
    }
    
    private function atualizarProcedimento($parDblIdProcedimento, $objMetadadosProcedimento, $parObjProtocolo, $parNumIdTramite)
    {
        if(!isset($parDblIdProcedimento)){
            throw new InfraException('Par�metro $parDblIdProcedimento n�o informado.');
        }
        
        if(!isset($objMetadadosProcedimento)){
            throw new InfraException('Par�metro $objMetadadosProcedimento n�o informado.');
        }
        
        if ($this->destinatarioReal) {
            $objDestinatario = $this->destinatarioReal;
        } else {
            $objDestinatario = $objMetadadosProcedimento->metadados->destinatario;
        }
        
        //Busca a unidade em ao qual o processo foi anteriormente expedido
        //Esta unidade dever� ser considerada para posterior desbloqueio do processo e reabertura
        $numIdUnidade = ProcessoEletronicoRN::obterUnidadeParaRegistroDocumento($parDblIdProcedimento);
        SessaoSEI::getInstance()->setNumIdUnidadeAtual($numIdUnidade);
        
        $objPenParametroRN = new PenParametroRN();
        
        try {
            $objSeiRN = new SeiRN();
            $objAtividadeDTO = new AtividadeDTO();
            $objAtividadeDTO->retDthConclusao();
            $objAtividadeDTO->setDblIdProtocolo($parDblIdProcedimento);
            $objAtividadeDTO->setNumIdUnidade($numIdUnidade);
            
            $objAtividadeRN = new AtividadeRN();
            $arrObjAtividadeDTO = $objAtividadeRN->listarRN0036($objAtividadeDTO);
            $flgReabrir = true;
            
            foreach ($arrObjAtividadeDTO as $objAtividadeDTO) {
                if ($objAtividadeDTO->getDthConclusao() == null) {
                    $flgReabrir = false;
                }
            }
            
            $objProcedimentoDTO = new ProcedimentoDTO();
            $objProcedimentoDTO->setDblIdProcedimento($parDblIdProcedimento);
            $objProcedimentoDTO->retTodos();
            $objProcedimentoDTO->retStrProtocoloProcedimentoFormatado();
            
            $objProcedimentoRN = new ProcedimentoRN();
            $objProcedimentoDTO = $objProcedimentoRN->consultarRN0201($objProcedimentoDTO);
            
            if($flgReabrir){
                $objEntradaReabrirProcessoAPI = new EntradaReabrirProcessoAPI();
                $objEntradaReabrirProcessoAPI->setIdProcedimento($parDblIdProcedimento);
                $objSeiRN->reabrirProcesso($objEntradaReabrirProcessoAPI);
            }
            
            ProcessoEletronicoRN::desbloquearProcesso($parDblIdProcedimento);
            
            $numUnidadeReceptora = $objPenParametroRN->getParametro('PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO');
            $this->enviarProcedimentoUnidade($objProcedimentoDTO, $numUnidadeReceptora);
            
        } finally {
            $numUnidadeReceptora = $objPenParametroRN->getParametro('PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO');
            SessaoSEI::getInstance()->setNumIdUnidadeAtual($numUnidadeReceptora);
        }
        
        $this->registrarAndamentoRecebimentoProcesso($objProcedimentoDTO, $objMetadadosProcedimento);
        
        //Cadastro das atividades para quando o destinat�rio � desviado pelo receptor (!3!)
        if ($this->destinatarioReal->numeroDeIdentificacaoDaEstrutura) {
            $this->gerarAndamentoUnidadeReceptora($parDblIdProcedimento);
        }
        
        //TODO: Obter c�digo da unidade atrav�s de mapeamento entre SEI e Barramento
        $objUnidadeDTO = $this->atribuirDadosUnidade($objProcedimentoDTO, $objDestinatario);
        
        $this->atribuirDocumentos($objProcedimentoDTO, $parObjProtocolo, $objUnidadeDTO, $objMetadadosProcedimento);
        
        $this->registrarProcedimentoNaoVisualizado($objProcedimentoDTO);
        
        //TODO: Avaliar necessidade de restringir refer�ncia circular entre processos
        //TODO: Registrar que o processo foi recebido com outros apensados. Necess�rio para posterior reenvio
        $this->atribuirProcessosApensados($objProcedimentoDTO, $parObjProtocolo->processoApensado, $objMetadadosProcedimento);
        
        //Realiza a altera��o dos metadados do processo
        //TODO: Implementar altera��o de todos os metadados
        $this->alterarMetadadosProcedimento($objProcedimentoDTO->getDblIdProcedimento(), $parObjProtocolo);
        
        return $objProcedimentoDTO;
    }
    
    
    private function gerarAndamentoUnidadeReceptora($parNumIdProcedimento)
    {
        $objUnidadeDTO = new PenUnidadeDTO();
        $objUnidadeDTO->setNumIdUnidadeRH($this->destinatarioReal->numeroDeIdentificacaoDaEstrutura);
        $objUnidadeDTO->setStrSinAtivo('S');
        $objUnidadeDTO->retStrDescricao(); //descricao
        
        $objUnidadeRN = new UnidadeRN();
        $objUnidadeDTO = $objUnidadeRN->consultarRN0125($objUnidadeDTO);
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('DESCRICAO');
        $objAtributoAndamentoDTO->setStrValor('Processo remetido para a unidade ' . $objUnidadeDTO->getStrDescricao());
        $objAtributoAndamentoDTO->setStrIdOrigem($this->destinatarioReal->numeroDeIdentificacaoDaEstrutura);
        
        $arrObjAtributoAndamentoDTO = array($objAtributoAndamentoDTO);
        
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDblIdProtocolo($parNumIdProcedimento);
        $objAtividadeDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objAtividadeDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());
        $objAtividadeDTO->setNumIdTarefa(TarefaRN::$TI_ATUALIZACAO_ANDAMENTO);
        $objAtividadeDTO->setArrObjAtributoAndamentoDTO($arrObjAtributoAndamentoDTO);
        $objAtividadeDTO->setDthConclusao(null);
        $objAtividadeDTO->setNumIdUsuarioConclusao(null);
        $objAtividadeDTO->setStrSinInicial('N');
        
        $objAtividadeRN = new AtividadeRN();
        $objAtividadeRN->gerarInternaRN0727($objAtividadeDTO);
    }
    
    private function gerarProcedimento($objMetadadosProcedimento, $parObjProtocolo)
    {
        if(!isset($objMetadadosProcedimento)){
            throw new InfraException('Par�metro $objMetadadosProcedimento n�o informado.');
        }
        
        //TODO: Usar dados do destinat�rio em outro m�todo espec�fico para envio
        // Dados do procedimento enviados pelos �rg�o externo integrado ao PEN
        $objRemetente = $objMetadadosProcedimento->metadados->remetente;
        $objDestinatario = $objMetadadosProcedimento->metadados->destinatario;
        
        //Atribui��o de dados do protocolo
        //TODO: Validar cada uma das informa��es de entrada do webservice
        $objProtocoloDTO = new ProtocoloDTO();
        $objProtocoloDTO->setDblIdProtocolo(null);
        $objProtocoloDTO->setStrDescricao(utf8_decode($parObjProtocolo->descricao));
        $objProtocoloDTO->setStrStaNivelAcessoLocal($this->obterNivelSigiloSEI($parObjProtocolo->nivelDeSigilo));
        
        if($this->obterNivelSigiloSEI($parObjProtocolo->nivelDeSigilo) == ProtocoloRN::$NA_RESTRITO){
            $objHipoteseLegalRecebido = new PenRelHipoteseLegalRecebidoRN();
            $objPenParametroRN = new PenParametroRN();
            $numIdHipoteseLegalPadrao = $objPenParametroRN->getParametro('HIPOTESE_LEGAL_PADRAO');
            
            if (!isset($parObjProtocolo->hipoteseLegal) || (isset($parObjProtocolo->hipoteseLegal) && empty($parObjProtocolo->hipoteseLegal->identificacao))) {
                $objProtocoloDTO->setNumIdHipoteseLegal($numIdHipoteseLegalPadrao);
            } else {
                
                $numIdHipoteseLegal = $objHipoteseLegalRecebido->getIdHipoteseLegalSEI($parObjProtocolo->hipoteseLegal->identificacao);
                if (empty($numIdHipoteseLegal)) {
                    $objProtocoloDTO->setNumIdHipoteseLegal($numIdHipoteseLegalPadrao);
                } else {
                    $objProtocoloDTO->setNumIdHipoteseLegal($numIdHipoteseLegal);
                }
            }
        }
        
        // O protocolo formatado do novo processo somente dever� reutilizar o n�mero definido pelo Remetente em caso de
        // tr�mites de processos. No caso de recebimento de documentos avulsos, o n�mero do novo processo sempre dever� ser
        // gerado pelo destinat�rio, conforme regras definidas em legisla��o vigente
        $strProtocoloFormatado = ($parObjProtocolo->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_PROCESSO) ? $parObjProtocolo->protocolo : null;
        $objProtocoloDTO->setStrProtocoloFormatado(utf8_decode($strProtocoloFormatado));
        $objProtocoloDTO->setDtaGeracao($this->objProcessoEletronicoRN->converterDataSEI($parObjProtocolo->dataHoraDeProducao));
        $objProtocoloDTO->setArrObjAnexoDTO(array());
        $objProtocoloDTO->setArrObjRelProtocoloAssuntoDTO(array());
        $objProtocoloDTO->setArrObjRelProtocoloProtocoloDTO(array());
        $this->atribuirParticipantes($objProtocoloDTO, $parObjProtocolo->interessado);
        
        $strDescricao = "";
        if(isset($parObjProtocolo->processoDeNegocio)){
            $strDescricao  = sprintf('Tipo de processo no �rg�o de origem: %s', utf8_decode($parObjProtocolo->processoDeNegocio)).PHP_EOL;
            $strDescricao .= $parObjProtocolo->observacao;
        }
        
        $objObservacaoDTO  = new ObservacaoDTO();
        
        // Cria��o da observa��o de aviso para qual � a real unidade emitida
        if ($this->destinatarioReal) {
            $objUnidadeDTO = new PenUnidadeDTO();
            $objUnidadeDTO->setNumIdUnidadeRH($this->destinatarioReal->numeroDeIdentificacaoDaEstrutura);
            $objUnidadeDTO->setStrSinAtivo('S');
            $objUnidadeDTO->retStrDescricao();
            
            $objUnidadeRN = new UnidadeRN();
            $objUnidadeDTO = $objUnidadeRN->consultarRN0125($objUnidadeDTO);
            $objObservacaoDTO->setStrDescricao($strDescricao . PHP_EOL .'Processo remetido para a unidade ' . $objUnidadeDTO->getStrDescricao());
        } else {
            $objObservacaoDTO->setStrDescricao($strDescricao);
        }
        
        $objProtocoloDTO->setArrObjObservacaoDTO(array($objObservacaoDTO));
        
        //Atribui��o de dados do procedimento
        $objProcedimentoDTO = new ProcedimentoDTO();
        $objProcedimentoDTO->setDblIdProcedimento(null);
        $objProcedimentoDTO->setObjProtocoloDTO($objProtocoloDTO);
        $objProcedimentoDTO->setStrNomeTipoProcedimento(utf8_decode($parObjProtocolo->processoDeNegocio));
        $objProcedimentoDTO->setDtaGeracaoProtocolo($this->objProcessoEletronicoRN->converterDataSEI($parObjProtocolo->dataHoraDeProducao));
        $objProcedimentoDTO->setStrProtocoloProcedimentoFormatado(utf8_decode($parObjProtocolo->protocolo));
        $objProcedimentoDTO->setStrSinGerarPendencia('S');
        $objProcedimentoDTO->setArrObjDocumentoDTO(array());
        
        $objPenParametroRN = new PenParametroRN();
        $numIdTipoProcedimento = $objPenParametroRN->getParametro('PEN_TIPO_PROCESSO_EXTERNO');
        $this->atribuirTipoProcedimento($objProcedimentoDTO, $numIdTipoProcedimento, $parObjProtocolo->processoDeNegocio);
        
        // Obt�m c�digo da unidade atrav�s de mapeamento entre SEI e Barramento
        $objUnidadeDTO = $this->atribuirDadosUnidade($objProcedimentoDTO, $objDestinatario);
        
        //TODO: Atribuir Dados do produtor do processo
        //TODO:Adicionar demais informa��es do processo
        //<protocoloAnterior>
        //<historico>
        
        //TODO: Avaliar necessidade de tal recurso
        //FeedSEIProtocolos::getInstance()->setBolAcumularFeeds(true);
        
        //TODO: Analisar impacto do par�metro SEI_HABILITAR_NUMERO_PROCESSO_INFORMADO no recebimento do processo
        //$objSeiRN = new SeiRN();
        //$objWSRetornoGerarProcedimentoDTO = $objSeiRN->gerarProcedimento($objWSEntradaGerarProcedimentoDTO);
        
        // Finalizar cria��o do procedimento
        $objProcedimentoRN = new ProcedimentoRN();
        
        // Verifica se o protocolo � do tipo documento avulso, se for gera um novo n�mero de protocolo
        if($parObjProtocolo->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_DOCUMENTO_AVULSO) {
            $strNumProtocoloDocumentoAvulso = $this->gerarNumeroProtocoloDocumentoAvulso($objUnidadeDTO, $objPenParametroRN);
            $objProcedimentoDTO->getObjProtocoloDTO()->setStrProtocoloFormatado($strNumProtocoloDocumentoAvulso);
        }
        
        $objProcedimentoDTOGerado = $objProcedimentoRN->gerarRN0156($objProcedimentoDTO);
        
        $objProcedimentoDTO->setDblIdProcedimento($objProcedimentoDTOGerado->getDblIdProcedimento());
        $objProcedimentoDTO->setStrProtocoloProcedimentoFormatado($objProcedimentoDTO->getObjProtocoloDTO()->getStrProtocoloFormatado());
        
        $this->registrarAndamentoRecebimentoProcesso($objProcedimentoDTO, $objMetadadosProcedimento);
        $this->atribuirDocumentos($objProcedimentoDTO, $parObjProtocolo, $objUnidadeDTO, $objMetadadosProcedimento);
        $this->registrarProcedimentoNaoVisualizado($objProcedimentoDTOGerado);
        
        //TODO: Avaliar necessidade de restringir refer�ncia circular entre processos
        //TODO: Registrar que o processo foi recebido com outros apensados. Necess�rio para posterior reenvio
        $this->atribuirProcessosApensados($objProcedimentoDTO, $parObjProtocolo->processoApensado, $objMetadadosProcedimento);
        
        return $objProcedimentoDTO;
    }
    
    /**
    * Gera o n�mero de protocolo para Documento avulso
    * @param $parObjUnidadeDTO
    * @param $parObjPenParametroRN
    * @return mixed
    * @throws InfraException
    */
    private function gerarNumeroProtocoloDocumentoAvulso($parObjUnidadeDTO, $parObjPenParametroRN)
    {
        try{
            // Alterado contexto de unidade atual para a unidade de destino do processo para que o n�cleo do SEI possa
            // gerar o n�mero de processo correto do destino e n�o o n�mero da unidade de recebimento do processo
            SessaoSEI::getInstance(false)->setNumIdUnidadeAtual($parObjUnidadeDTO->getNumIdUnidade());
            $objProtocoloRN = new ProtocoloRN();
            $strNumeroProcesso = $objProtocoloRN->gerarNumeracaoProcesso();
        }
        finally{
            SessaoSEI::getInstance(false)->setNumIdUnidadeAtual($parObjPenParametroRN->getParametro('PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO'));
        }
        
        return $strNumeroProcesso;
    }
    
    private function alterarMetadadosProcedimento($parNumIdProcedimento, $parObjMetadadoProcedimento)
    {
        //Realiza a altera��o dos metadados do processo(Por hora, apenas do n�vel de sigilo e hip�tese legal)
        $objProtocoloDTO = new ProtocoloDTO();
        $objProtocoloDTO->setDblIdProtocolo($parNumIdProcedimento);
        $objProtocoloDTO->setStrStaNivelAcessoLocal($this->obterNivelSigiloSEI($parObjMetadadoProcedimento->nivelDeSigilo));
        
        if($parObjMetadadoProcedimento->hipoteseLegal && $parObjMetadadoProcedimento->hipoteseLegal->identificacao){
            $objProtocoloDTO->setNumIdHipoteseLegal($this->obterHipoteseLegalSEI($parObjMetadadoProcedimento->hipoteseLegal->identificacao));
        }
        
        $objProtocoloRN = new ProtocoloRN();
        $objProtocoloRN->alterarRN0203($objProtocoloDTO);
    }
    
    private function alterarMetadadosDocumento($parNumIdProcedimento, $parNumIdDocumento, $parObjMetadadoDocumento)
    {
        //Realiza a altera��o dos metadados do documento(Por hora, apenas do n�vel de sigilo e hip�tese legal)
        $objProtocoloDTO = new ProtocoloDTO();
        $objProtocoloDTO->setDblIdProtocolo($parNumIdDocumento);
        $objProtocoloDTO->setStrStaNivelAcessoLocal($this->obterNivelSigiloSEI($parObjMetadadoDocumento->nivelDeSigilo));
        
        if($parObjMetadadoDocumento->hipoteseLegal && $parObjMetadadoDocumento->hipoteseLegal->identificacao){
            $objProtocoloDTO->setNumIdHipoteseLegal($this->obterHipoteseLegalSEI($parObjMetadadoDocumento->hipoteseLegal->identificacao));
        }
        
        $objProtocoloRN = new ProtocoloRN();
        $objProtocoloRN->alterarRN0203($objProtocoloDTO);
    }
    
    
    private function removerAndamentosProcedimento($parObjProtocoloDTO)
    {
        //TODO: Remover apenas as atividades geradas pelo recebimento do processo, n�o as atividades geradas anteriormente
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->retNumIdAtividade();
        $objAtividadeDTO->setDblIdProtocolo($parObjProtocoloDTO->getDblIdProcedimento());
        $objAtividadeDTO->setNumIdTarefa(TarefaRN::$TI_GERACAO_PROCEDIMENTO);
        
        $objAtividadeRN = new AtividadeRN();
        $objAtividadeRN->excluirRN0034($objAtividadeRN->listarRN0036($objAtividadeDTO));
    }
    
    private function registrarAndamentoRecebimentoProcesso(ProcedimentoDTO $objProcedimentoDTO, $parObjMetadadosProcedimento)
    {
        //Processo recebido da entidade @ENTIDADE_ORIGEM@ - @REPOSITORIO_ORIGEM@
        $objRemetente = $parObjMetadadosProcedimento->metadados->remetente;
        $objProcesso = $parObjMetadadosProcedimento->metadados->processo;
        $objProtocolo = ProcessoEletronicoRN::obterProtocoloDosMetadados($parObjMetadadosProcedimento);
        
        $arrObjAtributoAndamentoDTO = array();
        
        //TODO: Otimizar c�digo. Pesquisar 1 �nico elemento no barramento de servi�os
        $objRepositorioDTO = $this->objProcessoEletronicoRN->consultarRepositoriosDeEstruturas(
            $objRemetente->identificacaoDoRepositorioDeEstruturas
        );
        
        //TODO: Otimizar c�digo. Apenas buscar no barramento os dados da estrutura 1 �nica vez (AtribuirRemetente tamb�m utiliza)
        $objEstrutura = $this->objProcessoEletronicoRN->consultarEstrutura(
            $objRemetente->identificacaoDoRepositorioDeEstruturas,
            $objRemetente->numeroDeIdentificacaoDaEstrutura,
            true
        );
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('REPOSITORIO_ORIGEM');
        $objAtributoAndamentoDTO->setStrValor($objRepositorioDTO->getStrNome());
        $objAtributoAndamentoDTO->setStrIdOrigem($objRepositorioDTO->getNumId());
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('ENTIDADE_ORIGEM');
        $objAtributoAndamentoDTO->setStrValor($objEstrutura->nome);
        $objAtributoAndamentoDTO->setStrIdOrigem($objEstrutura->numeroDeIdentificacaoDaEstrutura);
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('PROCESSO');
        $objAtributoAndamentoDTO->setStrValor($objProtocolo->protocolo);
        $objAtributoAndamentoDTO->setStrIdOrigem(null);
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('USUARIO');
        $objAtributoAndamentoDTO->setStrValor(SessaoSEI::getInstance()->getStrNomeUsuario());
        $objAtributoAndamentoDTO->setStrIdOrigem(SessaoSEI::getInstance()->getNumIdUsuario());
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        //Obt�m dados da unidade de destino atribu�da anteriormente para o protocolo
        if($objProcedimentoDTO->isSetArrObjUnidadeDTO() && count($objProcedimentoDTO->getArrObjUnidadeDTO()) == 1) {
            $arrObjUnidadesDestinoDTO = $objProcedimentoDTO->getArrObjUnidadeDTO();
            $objUnidadesDestinoDTO = $arrObjUnidadesDestinoDTO[0];
            $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
            $objAtributoAndamentoDTO->setStrNome('UNIDADE_DESTINO');
            $objAtributoAndamentoDTO->setStrValor($objUnidadesDestinoDTO->getStrDescricao());
            $objAtributoAndamentoDTO->setStrIdOrigem($objUnidadesDestinoDTO->getNumIdUnidade());
            $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        }
        
        if(isset($objEstrutura->hierarquia)) {
            $arrObjNivel = $objEstrutura->hierarquia->nivel;
            
            $nome = "";
            $siglasUnidades = array();
            $siglasUnidades[] = $objEstrutura->sigla;
            
            foreach($arrObjNivel as $key => $objNivel){
                $siglasUnidades[] = $objNivel->sigla  ;
            }
            
            for($i = 1; $i <= 3; $i++){
                if(isset($siglasUnidades[count($siglasUnidades) - 1])){
                    unset($siglasUnidades[count($siglasUnidades) - 1]);
                }
            }
            
            foreach($siglasUnidades as $key => $nomeUnidade){
                if($key == (count($siglasUnidades) - 1)){
                    $nome .= $nomeUnidade." ";
                }else{
                    $nome .= $nomeUnidade." / ";
                }
            }
            
            $objNivel = current($arrObjNivel);
            
            $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
            $objAtributoAndamentoDTO->setStrNome('ENTIDADE_ORIGEM_HIRARQUIA');
            $objAtributoAndamentoDTO->setStrValor($nome);
            $objAtributoAndamentoDTO->setStrIdOrigem($objNivel->numeroDeIdentificacaoDaEstrutura);
            $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        }
        
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDblIdProtocolo($objProcedimentoDTO->getDblIdProcedimento());
        $objAtividadeDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objAtividadeDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());

        $bolEhProcesso = $objProtocolo->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_PROCESSO;
        $strIdTarefa = $bolEhProcesso ? ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_RECEBIDO : ProcessoEletronicoRN::$TI_DOCUMENTO_AVULSO_RECEBIDO;

        $objAtividadeDTO->setNumIdTarefa(ProcessoEletronicoRN::obterIdTarefaModulo($strIdTarefa));
        $objAtividadeDTO->setArrObjAtributoAndamentoDTO($arrObjAtributoAndamentoDTO);
        $objAtividadeDTO->setDthConclusao(null);
        $objAtividadeDTO->setNumIdUsuarioConclusao(null);
        $objAtividadeDTO->setStrSinInicial('N');
        
        $objAtividadeRN = new AtividadeRN();
        $objAtividadeRN->gerarInternaRN0727($objAtividadeDTO);
    }
    
    
    // Avaliar a necessidade de registrar os dados do remetente como participante do processo
    private function atribuirRemetente(ProtocoloDTO $objProtocoloDTO, $objRemetente)
    {
        $arrObjParticipantesDTO = array();
        if($objProtocoloDTO->isSetArrObjParticipanteDTO()) {
            $arrObjParticipantesDTO = $objProtocoloDTO->getArrObjParticipanteDTO();
        }
        
        //Obten��o de detalhes do remetente na infraestrutura do PEN
        $objEstruturaDTO = $this->objProcessoEletronicoRN->consultarEstrutura(
            $objRemetente->identificacaoDoRepositorioDeEstruturas,
            $objRemetente->numeroDeIdentificacaoDaEstrutura
        );
        
        if(!empty($objEstruturaDTO)) {
            $objParticipanteDTO  = new ParticipanteDTO();
            $objParticipanteDTO->setStrSiglaContato($objEstruturaDTO->getStrSigla());
            $objParticipanteDTO->setStrNomeContato($objEstruturaDTO->getStrNome());
            $objParticipanteDTO->setStrStaParticipacao(ParticipanteRN::$TP_REMETENTE);
            $objParticipanteDTO->setNumSequencia(0);
            $arrObjParticipantesDTO[] = $objParticipanteDTO;
            $arrObjParticipantesDTO = $this->prepararParticipantes($arrObjParticipantesDTO);
        }
        
        $objProtocoloDTO->setArrObjParticipanteDTO($arrObjParticipantesDTO);
    }
    
    
    private function atribuirParticipantes(ProtocoloDTO $objProtocoloDTO, $arrObjInteressados)
    {
        $arrObjParticipantesDTO = array();
        if($objProtocoloDTO->isSetArrObjParticipanteDTO()) {
            $arrObjParticipantesDTO = $objProtocoloDTO->getArrObjParticipanteDTO();
        }
        
        if (!is_array($arrObjInteressados)) {
            $arrObjInteressados = array($arrObjInteressados);
        }
        
        for($i=0; $i < count($arrObjInteressados); $i++){
            $objInteressado = $arrObjInteressados[$i];
            $objParticipanteDTO  = new ParticipanteDTO();
            $objParticipanteDTO->setStrSiglaContato($objInteressado->numeroDeIdentificacao);
            $objParticipanteDTO->setStrNomeContato(utf8_decode($objInteressado->nome));
            $objParticipanteDTO->setStrStaParticipacao(ParticipanteRN::$TP_INTERESSADO);
            $objParticipanteDTO->setNumSequencia($i);
            $arrObjParticipantesDTO[] = $objParticipanteDTO;
        }
        
        $arrObjParticipanteDTO = $this->prepararParticipantes($arrObjParticipantesDTO);
        $objProtocoloDTO->setArrObjParticipanteDTO($arrObjParticipantesDTO);
    }
    
    private function atribuirTipoProcedimento(ProcedimentoDTO $objProcedimentoDTO, $numIdTipoProcedimento)
    {
        if(!isset($numIdTipoProcedimento)){
            throw new InfraException('Par�metro $numIdTipoProcedimento n�o informado.');
        }
        
        $objTipoProcedimentoDTO = new TipoProcedimentoDTO();
        $objTipoProcedimentoDTO->retNumIdTipoProcedimento();
        $objTipoProcedimentoDTO->retStrNome();
        $objTipoProcedimentoDTO->setNumIdTipoProcedimento($numIdTipoProcedimento);
        
        $objTipoProcedimentoRN = new TipoProcedimentoRN();
        $objTipoProcedimentoDTO = $objTipoProcedimentoRN->consultarRN0267($objTipoProcedimentoDTO);
        
        if ($objTipoProcedimentoDTO==null){
            throw new InfraException('Tipo de processo n�o encontrado.');
        }
        
        $objProcedimentoDTO->setNumIdTipoProcedimento($objTipoProcedimentoDTO->getNumIdTipoProcedimento());
        $objProcedimentoDTO->setStrNomeTipoProcedimento($objTipoProcedimentoDTO->getStrNome());
        
        //Busca e adiciona os assuntos sugeridos para o tipo informado
        $objRelTipoProcedimentoAssuntoDTO = new RelTipoProcedimentoAssuntoDTO();
        $objRelTipoProcedimentoAssuntoDTO->retNumIdAssunto();
        $objRelTipoProcedimentoAssuntoDTO->retNumSequencia();
        $objRelTipoProcedimentoAssuntoDTO->setNumIdTipoProcedimento($objProcedimentoDTO->getNumIdTipoProcedimento());
        
        $objRelTipoProcedimentoAssuntoRN = new RelTipoProcedimentoAssuntoRN();
        $arrObjRelTipoProcedimentoAssuntoDTO = $objRelTipoProcedimentoAssuntoRN->listarRN0192($objRelTipoProcedimentoAssuntoDTO);
        $arrObjAssuntoDTO = $objProcedimentoDTO->getObjProtocoloDTO()->getArrObjRelProtocoloAssuntoDTO();
        
        foreach($arrObjRelTipoProcedimentoAssuntoDTO as $objRelTipoProcedimentoAssuntoDTO){
            $objRelProtocoloAssuntoDTO = new RelProtocoloAssuntoDTO();
            $objRelProtocoloAssuntoDTO->setNumIdAssunto($objRelTipoProcedimentoAssuntoDTO->getNumIdAssunto());
            $objRelProtocoloAssuntoDTO->setNumSequencia($objRelTipoProcedimentoAssuntoDTO->getNumSequencia());
            $arrObjAssuntoDTO[] = $objRelProtocoloAssuntoDTO;
        }
        
        $objProcedimentoDTO->getObjProtocoloDTO()->setArrObjRelProtocoloAssuntoDTO($arrObjAssuntoDTO);
    }
    
    protected function atribuirDadosUnidade(ProcedimentoDTO $objProcedimentoDTO, $objDestinatario)
    {
        
        if(!isset($objDestinatario)){
            throw new InfraException('Par�metro $objDestinatario n�o informado.');
        }
        
        $objUnidadeDTOEnvio = $this->obterUnidadeMapeada($objDestinatario->numeroDeIdentificacaoDaEstrutura);
        
        if(!isset($objUnidadeDTOEnvio))
        throw new InfraException('Unidade de destino n�o pode ser encontrada. Reposit�rio: ' . $objDestinatario->identificacaoDoRepositorioDeEstruturas .
        ', N�mero: ' . $objDestinatario->numeroDeIdentificacaoDaEstrutura);
        
        $arrObjUnidadeDTO = array();
        $arrObjUnidadeDTO[] = $objUnidadeDTOEnvio;
        $objProcedimentoDTO->setArrObjUnidadeDTO($arrObjUnidadeDTO);
        
        return $objUnidadeDTOEnvio;
    }
    
    // Avaliar a refatora��o para impedir a duplica��o de c�digo
    private function atribuirDocumentos($objProcedimentoDTO, $parObjProtocolo, $objUnidadeDTO, $parObjMetadadosProcedimento)
    {
        if(!isset($parObjProtocolo)) {
            throw new InfraException('Par�metro [parObjProtocolo] n�o informado.');
        }
        
        if(!isset($objUnidadeDTO)) {
            throw new InfraException('Unidade respons�vel pelo documento n�o informada.');
        }
        
        $arrObjDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        if(!isset($arrObjDocumentos) || count($arrObjDocumentos) == 0) {
            throw new InfraException('Lista de documentos do processo n�o informada.');
        }
        
        $strNumeroRegistro = $parObjMetadadosProcedimento->metadados->NRE;
        
        //Ordena��o dos documentos conforme informado pelo remetente. Campo documento->ordem
        usort($arrObjDocumentos, array("ReceberProcedimentoRN", "comparacaoOrdemDocumentos"));
        
        //Obter dados dos documentos j� registrados no sistema
        $objComponenteDigitalDTO = new ComponenteDigitalDTO();
        $objComponenteDigitalDTO->retNumOrdem();
        $objComponenteDigitalDTO->retDblIdProcedimento();
        $objComponenteDigitalDTO->retDblIdDocumento();
        $objComponenteDigitalDTO->retStrHashConteudo();
        $objComponenteDigitalDTO->retNumOrdemDocumento();
        $objComponenteDigitalDTO->setStrNumeroRegistro($strNumeroRegistro);
        $objComponenteDigitalDTO->setOrdNumOrdemDocumento(InfraDTO::$TIPO_ORDENACAO_ASC);
        
        $objComponenteDigitalBD = new ComponenteDigitalBD($this->getObjInfraIBanco());
        $arrObjComponenteDigitalDTO = $objComponenteDigitalBD->listar($objComponenteDigitalDTO);
        $arrObjComponenteDigitalDTOIndexado = InfraArray::indexarArrInfraDTO($arrObjComponenteDigitalDTO, "OrdemDocumento");
        
        $objProtocoloBD = new ProtocoloBD($this->getObjInfraIBanco());
        $objSeiRN = new SeiRN();
        
        $arrObjDocumentoDTO = array();
        
        $count = count($arrObjDocumentos);
        $this->gravarLogDebug("Quantidade de documentos para recedimento: $count", 4);
        foreach($arrObjDocumentos as $objDocumento){

            // Tratamento para atribui��o dos documentos do processo
            if(!isset($objDocumento->staTipoProtocolo)) {
                //TODO: Refatora��o - Encapsular rotina abaixo em uma fun��o cancelarDocumento
                //TODO: Rever necessidade de c�digo abaixo j� que trecho "foreach($this->documentosRetirados as $documentoCancelado)" 
                // no final deste m�todo j� implementa esta regra
                if(isset($objDocumento->retirado) && $objDocumento->retirado === true) {
                    if(array_key_exists($objDocumento->ordem, $arrObjComponenteDigitalDTOIndexado)) {
                        //Busca o ID do protocolo
                        $objComponenteIndexado = $arrObjComponenteDigitalDTOIndexado[$objDocumento->ordem];
                        $dblIdProtocolo = $objComponenteIndexado->getDblIdDocumento();
                        
                        //Instancia o DTO do protocolo
                        $objProtocoloDTO = new ProtocoloDTO();
                        $objProtocoloDTO->setDblIdProtocolo($dblIdProtocolo);
                        $objProtocoloDTO->retStrStaEstado();                    
                        $objProtocoloDTO = $objProtocoloBD->consultar($objProtocoloDTO);
                        
                        if($objProtocoloDTO->getStrStaEstado() != ProtocoloRN::$TE_DOCUMENTO_CANCELADO){
                            $objEntradaCancelarDocumentoAPI = new EntradaCancelarDocumentoAPI();
                            $objEntradaCancelarDocumentoAPI->setIdDocumento($dblIdProtocolo);
                            $objEntradaCancelarDocumentoAPI->setMotivo('Cancelado pelo remetente');
                            $objSeiRN->cancelarDocumento($objEntradaCancelarDocumentoAPI);
                        }
                        
                        continue;
                    }
                }
                
                if(array_key_exists($objDocumento->ordem, $arrObjComponenteDigitalDTOIndexado)){
                    $objComponenteDigitalDTO = $arrObjComponenteDigitalDTOIndexado[$objDocumento->ordem];
                    $this->alterarMetadadosDocumento($objComponenteDigitalDTO->getDblIdProcedimento(), $objComponenteDigitalDTO->getDblIdDocumento(), $objDocumento);
                    $objDocumento->idDocumentoSEI = $objComponenteDigitalDTO->getDblIdDocumento();
                    continue;
                }
                
                //Valida��o dos dados dos documentos
                if(!isset($objDocumento->especie)){
                    throw new InfraException('Esp�cie do documento ['.$objDocumento->descricao.'] n�o informada.');
                }
                
                $objDocumentoDTO = new DocumentoDTO();
                $objDocumentoDTO->setDblIdDocumento(null);
                $objDocumentoDTO->setDblIdProcedimento($objProcedimentoDTO->getDblIdProcedimento());
                
                $objSerieDTO = $this->obterSerieMapeada($objDocumento->especie->codigo);
                
                if ($objSerieDTO==null){
                    throw new InfraException('Tipo de documento [Esp�cie '.$objDocumento->especie->codigo.'] n�o encontrado.');
                }
                
                if (InfraString::isBolVazia($objDocumento->dataHoraDeProducao)) {
                    throw new InfraException('Data do documento n�o informada.');
                }
                
                $objProcedimentoDTO2 = new ProcedimentoDTO();
                $objProcedimentoDTO2->retDblIdProcedimento();
                $objProcedimentoDTO2->retNumIdUsuarioGeradorProtocolo();
                $objProcedimentoDTO2->retNumIdTipoProcedimento();
                $objProcedimentoDTO2->retStrStaNivelAcessoGlobalProtocolo();
                $objProcedimentoDTO2->retStrProtocoloProcedimentoFormatado();
                $objProcedimentoDTO2->retNumIdTipoProcedimento();
                $objProcedimentoDTO2->retStrNomeTipoProcedimento();
                $objProcedimentoDTO2->adicionarCriterio(
                    array('IdProcedimento','ProtocoloProcedimentoFormatado','ProtocoloProcedimentoFormatadoPesquisa'),
                    array(InfraDTO::$OPER_IGUAL,InfraDTO::$OPER_IGUAL,InfraDTO::$OPER_IGUAL),
                    array($objDocumentoDTO->getDblIdProcedimento(),$objDocumentoDTO->getDblIdProcedimento(),$objDocumentoDTO->getDblIdProcedimento()),
                    array(InfraDTO::$OPER_LOGICO_OR,InfraDTO::$OPER_LOGICO_OR)
                );
                
                $objProcedimentoRN = new ProcedimentoRN();
                $objProcedimentoDTO = $objProcedimentoRN->consultarRN0201($objProcedimentoDTO2);
                
                if ($objProcedimentoDTO==null){
                    throw new InfraException('Processo ['.$objDocumentoDTO->getDblIdProcedimento().'] n�o encontrado.');
                }
                
                $objDocumentoDTO->setDblIdProcedimento($objProcedimentoDTO->getDblIdProcedimento());
                $objDocumentoDTO->setNumIdSerie($objSerieDTO->getNumIdSerie());
                $objDocumentoDTO->setStrNomeSerie($objSerieDTO->getStrNome());
                
                $objDocumentoDTO->setDblIdDocumentoEdoc(null);
                $objDocumentoDTO->setDblIdDocumentoEdocBase(null);
                $objDocumentoDTO->setNumIdUnidadeResponsavel($objUnidadeDTO->getNumIdUnidade());
                $objDocumentoDTO->setNumIdTipoConferencia(null);
                $objDocumentoDTO->setStrConteudo(null);
                $objDocumentoDTO->setStrStaDocumento(DocumentoRN::$TD_EXTERNO);
                
                $objProtocoloDTO = new ProtocoloDTO();
                $objDocumentoDTO->setObjProtocoloDTO($objProtocoloDTO);
                $objProtocoloDTO->setDblIdProtocolo(null);
                $objProtocoloDTO->setStrStaProtocolo(ProtocoloRN::$TP_DOCUMENTO_RECEBIDO);
                
                if($objDocumento->descricao != '***'){
                    $objProtocoloDTO->setStrDescricao(utf8_decode($objDocumento->descricao));
                    $objDocumentoDTO->setStrNumero(utf8_decode($objDocumento->descricao));
                }else{
                    $objProtocoloDTO->setStrDescricao("");
                    $objDocumentoDTO->setStrNumero("");
                }
                
                //TODO: Avaliar regra de forma��o do n�mero do documento
                $objProtocoloDTO->setStrStaNivelAcessoLocal($this->obterNivelSigiloSEI($objDocumento->nivelDeSigilo));
                $objProtocoloDTO->setDtaGeracao($this->objProcessoEletronicoRN->converterDataSEI($objDocumento->dataHoraDeProducao));
                $objProtocoloDTO->setArrObjAnexoDTO(array());
                $objProtocoloDTO->setArrObjRelProtocoloAssuntoDTO(array());
                $objProtocoloDTO->setArrObjRelProtocoloProtocoloDTO(array());
                $objProtocoloDTO->setArrObjParticipanteDTO(array());
                
                //TODO: Analisar se o modelo de dados do PEN possui destinat�rios espec�ficos para os documentos
                //caso n�o possua, analisar o repasse de tais informa��es via par�metros adicionais
                $objObservacaoDTO  = new ObservacaoDTO();
                $strNumeroDocumentoOrigem = isset($objDocumento->protocolo) ? $objDocumento->protocolo : $objDocumento->produtor->numeroDeIdentificacao;
                $objObservacaoDTO->setStrDescricao("N�mero do Documento na Origem: " . $strNumeroDocumentoOrigem);
                $objProtocoloDTO->setArrObjObservacaoDTO(array($objObservacaoDTO));
                
                $bolReabriuAutomaticamente = false;
                if ($objProcedimentoDTO->getStrStaNivelAcessoGlobalProtocolo()==ProtocoloRN::$NA_PUBLICO || $objProcedimentoDTO->getStrStaNivelAcessoGlobalProtocolo()==ProtocoloRN::$NA_RESTRITO) {
                    $objAtividadeDTO = new AtividadeDTO();
                    $objAtividadeDTO->setDblIdProtocolo($objDocumentoDTO->getDblIdProcedimento());
                    $objAtividadeDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
                    $objAtividadeDTO->setDthConclusao(null);
                    
                    // Reabertura autom�tica de processo na unidade
                    $objAtividadeRN = new AtividadeRN();
                    if ($objAtividadeRN->contarRN0035($objAtividadeDTO) == 0) {
                        $objReabrirProcessoDTO = new ReabrirProcessoDTO();
                        $objReabrirProcessoDTO->setDblIdProcedimento($objDocumentoDTO->getDblIdProcedimento());
                        $objReabrirProcessoDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
                        $objReabrirProcessoDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());
                        $objProcedimentoRN->reabrirRN0966($objReabrirProcessoDTO);
                        $bolReabriuAutomaticamente = true;
                    }
                }
                
                $objTipoProcedimentoDTO = new TipoProcedimentoDTO();
                $objTipoProcedimentoDTO->retStrStaNivelAcessoSugestao();
                $objTipoProcedimentoDTO->retStrStaGrauSigiloSugestao();
                $objTipoProcedimentoDTO->retNumIdHipoteseLegalSugestao();
                $objTipoProcedimentoDTO->setBolExclusaoLogica(false);
                $objTipoProcedimentoDTO->setNumIdTipoProcedimento($objProcedimentoDTO->getNumIdTipoProcedimento());
                
                $objTipoProcedimentoRN = new TipoProcedimentoRN();
                $objTipoProcedimentoDTO = $objTipoProcedimentoRN->consultarRN0267($objTipoProcedimentoDTO);
                
                if (InfraString::isBolVazia($objDocumentoDTO->getObjProtocoloDTO()->getStrStaNivelAcessoLocal())
                || $objDocumentoDTO->getObjProtocoloDTO()->getStrStaNivelAcessoLocal() == $objTipoProcedimentoDTO->getStrStaNivelAcessoSugestao()) {
                    $objDocumentoDTO->getObjProtocoloDTO()->setStrStaNivelAcessoLocal($objTipoProcedimentoDTO->getStrStaNivelAcessoSugestao());
                    $objDocumentoDTO->getObjProtocoloDTO()->setStrStaGrauSigilo($objTipoProcedimentoDTO->getStrStaGrauSigiloSugestao());
                    $objDocumentoDTO->getObjProtocoloDTO()->setNumIdHipoteseLegal($objTipoProcedimentoDTO->getNumIdHipoteseLegalSugestao());
                }
                
                if ($this->obterNivelSigiloSEI($objDocumento->nivelDeSigilo) == ProtocoloRN::$NA_RESTRITO) {
                    $objHipoteseLegalRecebido = new PenRelHipoteseLegalRecebidoRN();
                    $objPenParametroRN = new PenParametroRN();
                    $numIdHipoteseLegalPadrao = $objPenParametroRN->getParametro('HIPOTESE_LEGAL_PADRAO');
                    
                    if (!isset($objDocumento->hipoteseLegal) || (isset($objDocumento->hipoteseLegal) && empty($objDocumento->hipoteseLegal->identificacao))) {
                        $objDocumentoDTO->getObjProtocoloDTO()->setNumIdHipoteseLegal($numIdHipoteseLegalPadrao);
                    } else {
                        
                        $numIdHipoteseLegal = $objHipoteseLegalRecebido->getIdHipoteseLegalSEI($objDocumento->hipoteseLegal->identificacao);
                        if (empty($numIdHipoteseLegal)) {
                            $objDocumentoDTO->getObjProtocoloDTO()->setNumIdHipoteseLegal($numIdHipoteseLegalPadrao);
                        } else {
                            $objDocumentoDTO->getObjProtocoloDTO()->setNumIdHipoteseLegal($numIdHipoteseLegal);
                        }
                    }
                }
                
                $objDocumentoDTO->getObjProtocoloDTO()->setArrObjParticipanteDTO($this->prepararParticipantes($objDocumentoDTO->getObjProtocoloDTO()->getArrObjParticipanteDTO()));
                
                $objDocumentoRN = new DocumentoRN();
                $strConteudoCodificado = $objDocumentoDTO->getStrConteudo();
                $objDocumentoDTO->setStrConteudo(null);
                $objDocumentoDTO->getObjProtocoloDTO()->setNumIdUnidadeGeradora(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
                $objDocumentoDTO->setStrSinBloqueado('N');
                
                //TODO: Fazer a atribui��o dos componentes digitais do processo a partir desse ponto
                $this->atribuirComponentesDigitais($objDocumentoDTO, $objDocumento->componenteDigital);
                $objDocumentoDTOGerado = $objDocumentoRN->cadastrarRN0003($objDocumentoDTO);
                
                $objAtividadeDTOVisualizacao = new AtividadeDTO();
                $objAtividadeDTOVisualizacao->setDblIdProtocolo($objDocumentoDTO->getDblIdProcedimento());
                $objAtividadeDTOVisualizacao->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
                
                if (!$bolReabriuAutomaticamente){
                    $objAtividadeDTOVisualizacao->setNumTipoVisualizacao(AtividadeRN::$TV_ATENCAO);
                }else{
                    $objAtividadeDTOVisualizacao->setNumTipoVisualizacao(AtividadeRN::$TV_NAO_VISUALIZADO | AtividadeRN::$TV_ATENCAO);
                }
                
                $objAtividadeRN = new AtividadeRN();
                $objAtividadeRN->atualizarVisualizacaoUnidade($objAtividadeDTOVisualizacao);
                
                $objDocumento->idDocumentoSEI = $objDocumentoDTO->getDblIdDocumento();
                $arrObjDocumentoDTO[] = $objDocumentoDTO;
                
                if(isset($objDocumento->retirado) && $objDocumento->retirado === true) {
                    $this->documentosRetirados[] = $objDocumento->idDocumentoSEI;
                }

              // Tratamento para atribui��o de processos anexados  
            } elseif($objDocumento->staTipoProtocolo == ProcessoEletronicoRN::$STA_TIPO_PROTOCOLO_PROCESSO) {
                $mumIdentificacaoTramite = $parObjMetadadosProcedimento->metadados->IDT;
                $objProcedimentoDTOAnexado = $this->gerarProcedimento($parObjMetadadosProcedimento, $objDocumento);

                $objEntradaAnexarProcessoAPI = new EntradaAnexarProcessoAPI();
                $objEntradaAnexarProcessoAPI->setIdProcedimentoPrincipal($objProcedimentoDTO->getDblIdProcedimento());
                $objEntradaAnexarProcessoAPI->setProtocoloProcedimentoPrincipal($objProcedimentoDTO->getStrProtocoloProcedimentoFormatado());
                $objEntradaAnexarProcessoAPI->setIdProcedimentoAnexado($objProcedimentoDTOAnexado->getDblIdProcedimento());
                $objEntradaAnexarProcessoAPI->setProtocoloProcedimentoAnexado($objProcedimentoDTOAnexado->getStrProtocoloProcedimentoFormatado());

                $objSeiRN = new SeiRN();
                $objSeiRN->anexarProcesso($objEntradaAnexarProcessoAPI);      
            }
        }
        
        foreach($this->documentosRetirados as $documentoCancelado){
            //Instancia o DTO do protocolo
            $objEntradaCancelarDocumentoAPI = new EntradaCancelarDocumentoAPI();
            $objEntradaCancelarDocumentoAPI->setIdDocumento($documentoCancelado);
            $objEntradaCancelarDocumentoAPI->setMotivo('Cancelado pelo remetente');
            $objSeiRN = new SeiRN();
            $objSeiRN->cancelarDocumento($objEntradaCancelarDocumentoAPI);
        }
        
        $objProcedimentoDTO->setArrObjDocumentoDTO($arrObjDocumentoDTO);
    }
    
    //TODO: M�todo dever� poder� ser transferido para a classe respons�vel por fazer o recebimento dos componentes digitais
    private function atribuirComponentesDigitais(DocumentoDTO $parObjDocumentoDTO, $parArrObjComponentesDigitais)
    {
        if(!isset($parArrObjComponentesDigitais)) {
            throw new InfraException('Componentes digitais do documento n�o informado.');
        }
        
        $arrObjAnexoDTO = array();
        if($parObjDocumentoDTO->getObjProtocoloDTO()->isSetArrObjAnexoDTO()) {
            $arrObjAnexoDTO = $parObjDocumentoDTO->getObjProtocoloDTO()->getArrObjAnexoDTO();
        }
        
        if (!is_array($parArrObjComponentesDigitais)) {
            $parArrObjComponentesDigitais = array($parArrObjComponentesDigitais);
        }
        
        $parObjDocumentoDTO->getObjProtocoloDTO()->setArrObjAnexoDTO($arrObjAnexoDTO);
    }
    
    
    private function atribuirProcessosApensados(ProcedimentoDTO $objProtocoloDTO, $objProcedimento, $parMetadadosProcedimento)
    {
        if(isset($objProcedimento->processoApensado)) {
            if(!is_array($objProcedimento->processoApensado)){
                $objProcedimento->processoApensado = array($objProcedimento->processoApensado);
            }
            
            $objProcedimentoDTOApensado = null;
            foreach ($objProcedimento->processoApensado as $processoApensado) {
                $objProcedimentoDTOApensado = $this->gerarProcedimento($parMetadadosProcedimento, $processoApensado);
                $this->relacionarProcedimentos($objProtocoloDTO, $objProcedimentoDTOApensado);
                $this->registrarProcedimentoNaoVisualizado($objProcedimentoDTOApensado);
            }
        }
    }
    
    
    private function validarDadosDestinatario(InfraException $objInfraException, $objMetadadosProcedimento)
    {
        $objDestinatario = $objMetadadosProcedimento->metadados->destinatario;

        if(!isset($objDestinatario)){
            throw new InfraException("Par�metro $objDestinatario n�o informado.");
        }
                        
        $objPenParametroRN = new PenParametroRN();
        $numIdRepositorioOrigem = $objPenParametroRN->getParametro('PEN_ID_REPOSITORIO_ORIGEM');
        $numIdRepositorioDestinoProcesso = $objDestinatario->identificacaoDoRepositorioDeEstruturas;
        $numeroDeIdentificacaoDaEstrutura = $objDestinatario->numeroDeIdentificacaoDaEstrutura;
        
        //Valida��o do reposit�rio de destino do processo
        if($numIdRepositorioDestinoProcesso != $numIdRepositorioOrigem){
            $objInfraException->adicionarValidacao("Identifica��o do reposit�rio de origem do processo [$numIdRepositorioDestinoProcesso] n�o reconhecida.");
        }
        
        //Valida��o do unidade de destino do processo
        $objUnidadeDTO = new PenUnidadeDTO();
        $objUnidadeDTO->setNumIdUnidadeRH($numeroDeIdentificacaoDaEstrutura);
        $objUnidadeDTO->setStrSinAtivo('S');
        $objUnidadeDTO->retNumIdUnidade();
        
        $objUnidadeRN = new UnidadeRN();
        $objUnidadeDTO = $objUnidadeRN->consultarRN0125($objUnidadeDTO);
        
        if(!isset($objUnidadeDTO)){
            $objInfraException->adicionarValidacao("Unidade [Estrutura: {$numeroDeIdentificacaoDaEstrutura}] n�o configurada para receber processos externos no sistema de destino.");
        }
    }
    
    private function obterNivelSigiloSEI($strNivelSigiloPEN) {
        switch ($strNivelSigiloPEN) {
            case ProcessoEletronicoRN::$STA_SIGILO_PUBLICO: return ProtocoloRN::$NA_PUBLICO;
            case ProcessoEletronicoRN::$STA_SIGILO_RESTRITO: return ProtocoloRN::$NA_RESTRITO;
            case ProcessoEletronicoRN::$STA_SIGILO_SIGILOSO: return ProtocoloRN::$NA_SIGILOSO;
        }
    }
    
    private function obterHipoteseLegalSEI($parNumIdHipoteseLegalPEN) {
        //Atribu� a hip�tese legal
        $objHipoteseLegalRecebido = new PenRelHipoteseLegalRecebidoRN();
        $objPenParametroRN = new PenParametroRN();
        $numIdHipoteseLegalPadrao = $objPenParametroRN->getParametro('HIPOTESE_LEGAL_PADRAO');
        
        $numIdHipoteseLegal = $objHipoteseLegalRecebido->getIdHipoteseLegalSEI($parNumIdHipoteseLegalPEN);
        
        if (empty($numIdHipoteseLegal)) {
            return $numIdHipoteseLegalPadrao;
        } else {
            return $numIdHipoteseLegal;
        }
    }
    
    //TODO: Implementar o mapeamento entre as unidade do SEI e Barramento de Servi�os (Secretaria de Sa�de: 218794)
    private function obterUnidadeMapeada($numIdentificacaoDaEstrutura)
    {
        $objUnidadeDTO = new PenUnidadeDTO();
        $objUnidadeDTO->setNumIdUnidadeRH($numIdentificacaoDaEstrutura);
        $objUnidadeDTO->setStrSinAtivo('S');
        $objUnidadeDTO->retNumIdUnidade();
        $objUnidadeDTO->retNumIdOrgao();
        $objUnidadeDTO->retStrSigla();
        $objUnidadeDTO->retStrDescricao();
        
        $objUnidadeRN = new UnidadeRN();
        return $objUnidadeRN->consultarRN0125($objUnidadeDTO);
    }
    
    
    private function obterSerieMapeada($numCodigoEspecie)
    {
        $objSerieDTO = null;
        
        $objMapDTO = new PenRelTipoDocMapRecebidoDTO();
        $objMapDTO->setNumCodigoEspecie($numCodigoEspecie);
        $objMapDTO->retNumIdSerie();
        
        $objGenericoBD = new GenericoBD($this->getObjInfraIBanco());
        $objMapDTO = $objGenericoBD->consultar($objMapDTO);
        
        if(empty($objMapDTO)) {
            $objMapDTO = new PenRelTipoDocMapRecebidoDTO();
            $objMapDTO->retNumIdSerie();
            $objMapDTO->setStrPadrao('S');
            $objMapDTO->setNumMaxRegistrosRetorno(1);
            $objMapDTO = $objGenericoBD->consultar($objMapDTO);
        }
        
        if(!empty($objMapDTO)) {
            $objSerieDTO = new SerieDTO();
            $objSerieDTO->retStrNome();
            $objSerieDTO->retNumIdSerie();
            $objSerieDTO->setNumIdSerie($objMapDTO->getNumIdSerie());
            
            $objSerieRN = new SerieRN();
            $objSerieDTO = $objSerieRN->consultarRN0644($objSerieDTO);
        }
        
        return $objSerieDTO;
    }
    
    private function relacionarProcedimentos($objProcedimentoDTO1, $objProcedimentoDTO2)
    {
        if(!isset($objProcedimentoDTO1) || !isset($objProcedimentoDTO1)) {
            throw new InfraException('Par�metro $objProcedimentoDTO n�o informado.');
        }
        
        $objRelProtocoloProtocoloDTO = new RelProtocoloProtocoloDTO();
        $objRelProtocoloProtocoloDTO->setDblIdProtocolo1($objProcedimentoDTO2->getDblIdProcedimento());
        $objRelProtocoloProtocoloDTO->setDblIdProtocolo2($objProcedimentoDTO1->getDblIdProcedimento());
        $objRelProtocoloProtocoloDTO->setStrStaAssociacao(RelProtocoloProtocoloRN::$TA_PROCEDIMENTO_RELACIONADO);
        $objRelProtocoloProtocoloDTO->setStrMotivo(self::STR_APENSACAO_PROCEDIMENTOS);
        
        $objProcedimentoRN = new ProcedimentoRN();
        $objProcedimentoRN->relacionarProcedimentoRN1020($objRelProtocoloProtocoloDTO);
    }
    
    //TODO: M�todo identico ao localizado na classe SeiRN:2214
    //Refatorar c�digo para evitar problemas de manuten��o
    private function prepararParticipantes($arrObjParticipanteDTO)
    {
        $objContatoRN = new ContatoRN();
        $objUsuarioRN = new UsuarioRN();
        
        foreach($arrObjParticipanteDTO as $objParticipanteDTO) {
            $objContatoDTO = new ContatoDTO();
            $objContatoDTO->retNumIdContato();
            
            if (!InfraString::isBolVazia($objParticipanteDTO->getStrSiglaContato()) && !InfraString::isBolVazia($objParticipanteDTO->getStrNomeContato())) {
                $objContatoDTO->setStrSigla($objParticipanteDTO->getStrSiglaContato());
                $objContatoDTO->setStrNome($objParticipanteDTO->getStrNomeContato());
                
            }  else if (!InfraString::isBolVazia($objParticipanteDTO->getStrSiglaContato())) {
                $objContatoDTO->setStrSigla($objParticipanteDTO->getStrSiglaContato());
                
            } else if (!InfraString::isBolVazia($objParticipanteDTO->getStrNomeContato())) {
                $objContatoDTO->setStrNome($objParticipanteDTO->getStrNomeContato());
            } else {
                if ($objParticipanteDTO->getStrStaParticipacao()==ParticipanteRN::$TP_INTERESSADO) {
                    throw new InfraException('Interessado vazio ou nulo.');
                }
                else if ($objParticipanteDTO->getStrStaParticipacao()==ParticipanteRN::$TP_REMETENTE) {
                    throw new InfraException('Remetente vazio ou nulo.');
                }
                else if ($objParticipanteDTO->getStrStaParticipacao()==ParticipanteRN::$TP_DESTINATARIO) {
                    throw new InfraException('Destinat�rio vazio ou nulo.');
                }
            }
            
            $arrObjContatoDTO = $objContatoRN->listarRN0325($objContatoDTO);
            if (count($arrObjContatoDTO)) {
                $objContatoDTO = null;
                
                //preferencia para contatos que representam usuarios
                foreach($arrObjContatoDTO as $dto) {
                    $objUsuarioDTO = new UsuarioDTO();
                    $objUsuarioDTO->setBolExclusaoLogica(false);
                    $objUsuarioDTO->setNumIdContato($dto->getNumIdContato());
                    
                    if ($objUsuarioRN->contarRN0492($objUsuarioDTO)) {
                        $objContatoDTO = $dto; break;
                    }
                }
                
                //nao achou contato de usuario pega o primeiro retornado
                if ($objContatoDTO==null)   {
                    $objContatoDTO = $arrObjContatoDTO[0];
                }
            } else {
                $objContatoDTO = $objContatoRN->cadastrarContextoTemporario($objContatoDTO);
            }
            
            $objParticipanteDTO->setNumIdContato($objContatoDTO->getNumIdContato());
        }
        
        return $arrObjParticipanteDTO;
    }
    
    private function registrarProcedimentoNaoVisualizado(ProcedimentoDTO $parObjProcedimentoDTO)
    {
        $objAtividadeDTOVisualizacao = new AtividadeDTO();
        $objAtividadeDTOVisualizacao->setDblIdProtocolo($parObjProcedimentoDTO->getDblIdProcedimento());
        $objAtividadeDTOVisualizacao->setNumTipoVisualizacao(AtividadeRN::$TV_NAO_VISUALIZADO);
        
        $objAtividadeRN = new AtividadeRN();
        $objAtividadeRN->atualizarVisualizacao($objAtividadeDTOVisualizacao);
    }
    
    private function enviarProcedimentoUnidade(ProcedimentoDTO $parObjProcedimentoDTO, $parUnidadeDestino=null, $retransmissao=false)
    {
        $objAtividadeRN = new PenAtividadeRN();
        $objPenParametroRN = new PenParametroRN();
        $objInfraException = new InfraException();
        
        $strEnviaEmailNotificacao = 'N';
        $numIdUnidade = $parUnidadeDestino;
        
        //Caso a unidade de destino n�o tenha sido informada, considerar as unidades atribu�das ao processo
        if(is_null($numIdUnidade)){
            if(!$parObjProcedimentoDTO->isSetArrObjUnidadeDTO() || count($parObjProcedimentoDTO->getArrObjUnidadeDTO()) == 0) {
                $objInfraException->lancarValidacao('Unidade de destino do processo n�o informada.');
            }
            
            $arrObjUnidadeDTO = $parObjProcedimentoDTO->getArrObjUnidadeDTO();
            if(count($parObjProcedimentoDTO->getArrObjUnidadeDTO()) > 1) {
                $objInfraException->lancarValidacao('N�o permitido a indica��o de m�ltiplas unidades de destino para um processo recebido externamente.');
            }
            
            $arrObjUnidadeDTO = array_values($parObjProcedimentoDTO->getArrObjUnidadeDTO());
            $objUnidadeDTO = $arrObjUnidadeDTO[0];
            $numIdUnidade = $objUnidadeDTO->getNumIdUnidade();
            
            //Somente considera regra de envio de e-mail para unidades vinculadas ao processo
            $strEnviaEmailNotificacao = $objPenParametroRN->getParametro('PEN_ENVIA_EMAIL_NOTIFICACAO_RECEBIMENTO');
        }
        
        
        $objProcedimentoDTO = new ProcedimentoDTO();
        $objProcedimentoDTO->retDblIdProcedimento();
        $objProcedimentoDTO->retNumIdTipoProcedimento();
        $objProcedimentoDTO->retStrProtocoloProcedimentoFormatado();
        $objProcedimentoDTO->retNumIdTipoProcedimento();
        $objProcedimentoDTO->retStrNomeTipoProcedimento();
        $objProcedimentoDTO->retStrStaNivelAcessoGlobalProtocolo();
        $objProcedimentoDTO->setDblIdProcedimento($parObjProcedimentoDTO->getDblIdProcedimento());
        
        
        $objProcedimentoRN = new ProcedimentoRN();
        $objProcedimentoDTO = $objProcedimentoRN->consultarRN0201($objProcedimentoDTO);
        
        if ($objProcedimentoDTO == null || $objProcedimentoDTO->getStrStaNivelAcessoGlobalProtocolo()==ProtocoloRN::$NA_SIGILOSO) {
            $objInfraException->lancarValidacao('Processo ['.$parObjProcedimentoDTO->getStrProtocoloProcedimentoFormatado().'] n�o encontrado.');
        }
        
        if ($objProcedimentoDTO->getStrStaNivelAcessoGlobalProtocolo()==ProtocoloRN::$NA_RESTRITO) {
            $objAcessoDTO = new AcessoDTO();
            $objAcessoDTO->setDblIdProtocolo($objProcedimentoDTO->getDblIdProcedimento());
            $objAcessoDTO->setNumIdUnidade($numIdUnidade);
            
            $objAcessoRN = new AcessoRN();
            if ($objAcessoRN->contar($objAcessoDTO)==0) {
                //  AVALIAR $objInfraException->adicionarValidacao('Unidade ['.$objUnidadeDTO->getStrSigla().'] n�o possui acesso ao processo ['.$objProcedimentoDTO->getStrProtocoloProcedimentoFormatado().'].');
            }
        }
        
        $objPesquisaPendenciaDTO = new PesquisaPendenciaDTO();
        $objPesquisaPendenciaDTO->setDblIdProtocolo(array($objProcedimentoDTO->getDblIdProcedimento()));
        $objPesquisaPendenciaDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());
        $objPesquisaPendenciaDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        
        if($retransmissao){
            $objAtividadeRN->setStatusPesquisa(false);
        }
        
        $objAtividadeDTO2 = new AtividadeDTO();
        $objAtividadeDTO2->setDblIdProtocolo($objProcedimentoDTO->getDblIdProcedimento());
        $objAtividadeDTO2->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objAtividadeDTO2->setDthConclusao(null);
        
        if ($objAtividadeRN->contarRN0035($objAtividadeDTO2) == 0) {
            //reabertura autom�tica
            $objReabrirProcessoDTO = new ReabrirProcessoDTO();
            $objReabrirProcessoDTO->setDblIdProcedimento($objAtividadeDTO2->getDblIdProtocolo());
            $objReabrirProcessoDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());
            $objReabrirProcessoDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
            $objProcedimentoRN->reabrirRN0966($objReabrirProcessoDTO);
        }
        
        //$objPenAtividadeRN = new PenAtividadeRN();
        $arrObjProcedimentoDTO = $objAtividadeRN->listarPendenciasRN0754($objPesquisaPendenciaDTO);
        
        $objInfraException->lancarValidacoes();
        
        $objEnviarProcessoDTO = new EnviarProcessoDTO();
        $objEnviarProcessoDTO->setArrAtividadesOrigem($arrObjProcedimentoDTO[0]->getArrObjAtividadeDTO());
        
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDblIdProtocolo($objProcedimentoDTO->getDblIdProcedimento());
        $objAtividadeDTO->setNumIdUsuario(null);
        $objAtividadeDTO->setNumIdUsuarioOrigem(SessaoSEI::getInstance()->getNumIdUsuario());
        $objAtividadeDTO->setNumIdUnidade($numIdUnidade);
        $objAtividadeDTO->setNumIdUnidadeOrigem(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objEnviarProcessoDTO->setArrAtividades(array($objAtividadeDTO));
        
        $objEnviarProcessoDTO->setStrSinManterAberto('N');
        $objEnviarProcessoDTO->setStrSinEnviarEmailNotificacao($strEnviaEmailNotificacao);
        $objEnviarProcessoDTO->setStrSinRemoverAnotacoes('S');
        $objEnviarProcessoDTO->setDtaPrazo(null);
        $objEnviarProcessoDTO->setNumDias(null);
        $objEnviarProcessoDTO->setStrSinDiasUteis('N');
        
        $objAtividadeRN->enviarRN0023($objEnviarProcessoDTO);
        
    }
    
    static function comparacaoOrdemDocumentos($parDocumento1, $parDocumento2)
    {
        $numOrdemDocumento1 = strtolower($parDocumento1->ordem);
        $numOrdemDocumento2 = strtolower($parDocumento2->ordem);
        return $numOrdemDocumento1 - $numOrdemDocumento2;
    }
    
    
    protected function receberTramitesRecusadosControlado($parNumIdentificacaoTramite)
    {
        try {
            if (empty($parNumIdentificacaoTramite)) {
                throw new InfraException('Par�metro $parNumIdentificacaoTramite n�o informado.');
            }
            
            //Busca os dados do tr�mite no barramento
            $tramite = $this->objProcessoEletronicoRN->consultarTramites($parNumIdentificacaoTramite);
            
            if(!isset($tramite[0])){
                throw new InfraException("N�o foi encontrado no PEN o tr�mite de n�mero {$parNumIdentificacaoTramite} para realizar a ci�ncia da recusa");
            }
            
            $tramite = $tramite[0];
            
            $objTramiteDTO = new TramiteDTO();
            $objTramiteDTO->setNumIdTramite($parNumIdentificacaoTramite);
            $objTramiteDTO->retNumIdUnidade();
            
            $objTramiteBD = new TramiteBD(BancoSEI::getInstance());
            $objTramiteDTO = $objTramiteBD->consultar($objTramiteDTO);
            
            if(isset($objTramiteDTO)){
                SessaoSEI::getInstance(false)->simularLogin('SEI', null, null, $objTramiteDTO->getNumIdUnidade());
                
                //Busca os dados do procedimento
                $this->gravarLogDebug("Buscando os dados de procedimento com NRE " . $tramite->NRE, 2);
                $objProcessoEletronicoDTO = new ProcessoEletronicoDTO();
                $objProcessoEletronicoDTO->setStrNumeroRegistro($tramite->NRE);
                $objProcessoEletronicoDTO->retDblIdProcedimento();
                $objProcessoEletronicoBD = new ProcessoEletronicoBD($this->getObjInfraIBanco());
                $objProcessoEletronicoDTO = $objProcessoEletronicoBD->consultar($objProcessoEletronicoDTO);
                
                //Busca a �ltima atividade de tr�mite externo
                $this->gravarLogDebug("Buscando �ltima atividade de tr�mite externo do processo " . $objProcessoEletronicoDTO->getDblIdProcedimento(), 2);
                $objAtividadeDTO = new AtividadeDTO();
                $objAtividadeDTO->setDblIdProtocolo($objProcessoEletronicoDTO->getDblIdProcedimento());
                $objAtividadeDTO->setNumIdTarefa(ProcessoEletronicoRN::obterIdTarefaModulo(ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_EXPEDIDO));
                $objAtividadeDTO->setNumMaxRegistrosRetorno(1);
                $objAtividadeDTO->setOrdDthAbertura(InfraDTO::$TIPO_ORDENACAO_DESC);
                $objAtividadeDTO->retNumIdAtividade();
                $objAtividadeBD = new AtividadeBD($this->getObjInfraIBanco());
                $objAtividadeDTO = $objAtividadeBD->consultar($objAtividadeDTO);
                
                //Busca a unidade de destino
                $this->gravarLogDebug("Buscando informa��es sobre a unidade de destino", 2);
                $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
                $objAtributoAndamentoDTO->setNumIdAtividade($objAtividadeDTO->getNumIdAtividade());
                $objAtributoAndamentoDTO->setStrNome('UNIDADE_DESTINO');
                $objAtributoAndamentoDTO->retStrValor();
                $objAtributoAndamentoBD = new AtributoAndamentoBD($this->getObjInfraIBanco());
                $objAtributoAndamentoDTO = $objAtributoAndamentoBD->consultar($objAtributoAndamentoDTO);
                
                //Monta o DTO de receber tramite recusado
                $this->gravarLogDebug("Preparando recebimento de tr�mite " . $parNumIdentificacaoTramite . " recusado", 2);
                $objReceberTramiteRecusadoDTO = new ReceberTramiteRecusadoDTO();
                $objReceberTramiteRecusadoDTO->setNumIdTramite($parNumIdentificacaoTramite);
                $objReceberTramiteRecusadoDTO->setNumIdProtocolo($objProcessoEletronicoDTO->getDblIdProcedimento());
                $objReceberTramiteRecusadoDTO->setNumIdUnidadeOrigem(null);
                $objReceberTramiteRecusadoDTO->setNumIdTarefa(ProcessoEletronicoRN::obterIdTarefaModulo(ProcessoEletronicoRN::$TI_PROCESSO_ELETRONICO_PROCESSO_TRAMITE_RECUSADO));
                $objReceberTramiteRecusadoDTO->setStrMotivoRecusa(utf8_decode($tramite->justificativaDaRecusa));
                $objReceberTramiteRecusadoDTO->setStrNomeUnidadeDestino($objAtributoAndamentoDTO->getStrValor());
                
                //Faz o tratamento do processo e do tr�mite recusado
                $this->gravarLogDebug("Atualizando dados do processo " . $objProcessoEletronicoDTO->getDblIdProcedimento() ." e do tr�mite recusado " . $parNumIdentificacaoTramite, 2);
                $this->receberTramiteRecusadoInterno($objReceberTramiteRecusadoDTO);
            }
            
            $this->gravarLogDebug("Notificando servi�os do PEN sobre ci�ncia da recusa do tr�mite " . $parNumIdentificacaoTramite, 4);
            $this->objProcessoEletronicoRN->cienciaRecusa($parNumIdentificacaoTramite);
            
        } catch (Exception $e) {
            $mensagemErro = InfraException::inspecionar($e);
            $this->gravarLogDebug($mensagemErro);
            LogSEI::getInstance()->gravar($mensagemErro);
            throw $e;
        }
    }
    
    
    protected function receberTramiteRecusadoInternoControlado(ReceberTramiteRecusadoDTO $objReceberTramiteRecusadoDTO)
    {
        //Verifica se processo est� fechado, reabrindo-o caso necess�rio
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDblIdProtocolo($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
        $objAtividadeDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objAtividadeDTO->setDthConclusao(null);
        $objAtividadeRN = new AtividadeRN();
        if ($objAtividadeRN->contarRN0035($objAtividadeDTO) == 0) {
            $this->gravarLogDebug("Reabrindo automaticamente o processo", 4);
            $objReabrirProcessoDTO = new ReabrirProcessoDTO();
            $objReabrirProcessoDTO->setDblIdProcedimento($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
            $objReabrirProcessoDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
            $objReabrirProcessoDTO->setNumIdUsuario(SessaoSEI::getInstance()->getNumIdUsuario());
            $objProcedimentoRN = new ProcedimentoRN();
            $objProcedimentoRN->reabrirRN0966($objReabrirProcessoDTO);
        }
        
        //Realiza o desbloqueio do processo
        $this->gravarLogDebug("Realizando o desbloqueio do processo", 4);
        $objProtocoloDTO = new ProtocoloDTO();
        $objProtocoloDTO->setDblIdProtocolo($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
        $objProtocoloDTO->setStrStaEstado(ProtocoloRN::$TE_PROCEDIMENTO_BLOQUEADO);
        $objProtocoloRN = new ProtocoloRN();
        if($objProtocoloRN->contarRN0667($objProtocoloDTO) != 0) {
            ProcessoEletronicoRN::desbloquearProcesso($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
        } else {
            $this->gravarLogDebug("Processo " . $objReceberTramiteRecusadoDTO->getNumIdProtocolo() . " j� se encontra desbloqueado!", 6);
        }
        
        //Adiciona um andamento para o tr�mite recusado
        $this->gravarLogDebug("Adicionando andamento para registro da recusa do tr�mite", 4);
        $arrObjAtributoAndamentoDTO = array();
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('MOTIVO');
        $objAtributoAndamentoDTO->setStrValor($objReceberTramiteRecusadoDTO->getStrMotivoRecusa());
        $objAtributoAndamentoDTO->setStrIdOrigem($objReceberTramiteRecusadoDTO->getNumIdUnidadeOrigem());
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        $objAtributoAndamentoDTO = new AtributoAndamentoDTO();
        $objAtributoAndamentoDTO->setStrNome('UNIDADE_DESTINO');
        $objAtributoAndamentoDTO->setStrValor($objReceberTramiteRecusadoDTO->getStrNomeUnidadeDestino());
        $objAtributoAndamentoDTO->setStrIdOrigem($objReceberTramiteRecusadoDTO->getNumIdUnidadeOrigem());
        $arrObjAtributoAndamentoDTO[] = $objAtributoAndamentoDTO;
        
        $objAtividadeDTO = new AtividadeDTO();
        $objAtividadeDTO->setDblIdProtocolo($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
        $objAtividadeDTO->setNumIdUnidade(SessaoSEI::getInstance()->getNumIdUnidadeAtual());
        $objAtividadeDTO->setNumIdTarefa($objReceberTramiteRecusadoDTO->getNumIdTarefa());
        $objAtividadeDTO->setArrObjAtributoAndamentoDTO($arrObjAtributoAndamentoDTO);
        
        $objAtividadeRN = new AtividadeRN();
        $objAtividadeRN->gerarInternaRN0727($objAtividadeDTO);
        
        //Sinaliza na PenProtocolo que o processo obteve recusa
        $this->gravarLogDebug("Atualizando protocolo sobre obten��o da ci�ncia de recusa", 4);
        $objProtocolo = new PenProtocoloDTO();
        $objProtocolo->setDblIdProtocolo($objReceberTramiteRecusadoDTO->getNumIdProtocolo());
        $objProtocolo->setStrSinObteveRecusa('S');
        $objProtocoloBD = new ProtocoloBD($this->getObjInfraIBanco());
        $objProtocoloBD->alterar($objProtocolo);
    }
    
    
    /**
    * M�todo que realiza a valida��o da extens�o dos componentes digitais a serem recebidos
    *
    * @param integer $parIdTramite
    * @param object $parObjProtocolo
    * @throws InfraException
    */
    public function validarExtensaoComponentesDigitais($parIdTramite, $parObjProtocolo)
    {
        $arrDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        $arquivoExtensaoBD = new ArquivoExtensaoBD($this->getObjInfraIBanco());
        
        foreach($arrDocumentos as $objDocumento){
            if(!isset($objDocumento->retirado) || $objDocumento->retirado == false){
                $arrComponentesDigitais = $objDocumento->componenteDigital;
                if(isset($arrComponentesDigitais) && !is_array($arrComponentesDigitais)){
                    $arrComponentesDigitais = array($arrComponentesDigitais);
                }
    
                foreach ($arrComponentesDigitais as $componenteDigital) {
                    //Busca o nome do documento
                    $nomeDocumento = $componenteDigital->nome;
    
                    //Busca pela extens�o do documento
                    $arrNomeDocumento = explode('.', $nomeDocumento);
                    $extDocumento = $arrNomeDocumento[count($arrNomeDocumento) - 1];
    
                    //Verifica se a extens�o do arquivo est� cadastrada e ativa
                    $arquivoExtensaoDTO = new ArquivoExtensaoDTO();
                    $arquivoExtensaoDTO->setStrSinAtivo('S');
                    $arquivoExtensaoDTO->setStrExtensao($extDocumento);
                    $arquivoExtensaoDTO->retStrExtensao();
    
                    if($arquivoExtensaoBD->contar($arquivoExtensaoDTO) == 0){
                        $strMensagem = "Processo recusado devido a exist�ncia de documento em formato {$extDocumento} n�o permitido pelo sistema.";
                        $this->objProcessoEletronicoRN->recusarTramite($parIdTramite, $strMensagem, ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_FORMATO);
                        throw new InfraException($strMensagem);
                    }
                }    
            }
        }
    }
    
    /**
    * M�todo que verifica as permiss�es de escrita nos diret�rios utilizados no recebimento de processos e documentos
    *
    * @param integer $parIdTramite
    * @throws InfraException
    */
    public function verificarPermissoesDiretorios($parIdTramite)
    {
        //Verifica se o usu�rio possui permiss�es de escrita no reposit�rio de arquivos externos
        if(!is_writable(ConfiguracaoSEI::getInstance()->getValor('SEI', 'RepositorioArquivos'))) {
            $this->objProcessoEletronicoRN->recusarTramite($parIdTramite, 'O sistema n�o possui permiss�o de escrita no diret�rio de armazenamento de documentos externos', ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_OUTROU);
            throw new InfraException('O sistema n�o possui permiss�o de escrita no diret�rio de armazenamento de documentos externos');
        }
        
        //Verifica se o usu�rio possui permiss�es de escrita no diret�rio tempor�rio de arquivos
        if(!is_writable(DIR_SEI_TEMP)){
            $this->objProcessoEletronicoRN->recusarTramite($parIdTramite, 'O sistema n�o possui permiss�o de escrita no diret�rio de armazenamento de arquivos tempor�rios do sistema.', ProcessoEletronicoRN::MTV_RCSR_TRAM_CD_OUTROU);
            throw new InfraException('O sistema n�o possui permiss�o de escrita no diret�rio de armazenamento de arquivos tempor�rios do sistema.');
            
        }
    }
    
    private function sincronizarRecebimentoProcessos($parStrNumeroRegistro, $parNumIdentificacaoTramite, $numIdTarefa)
    {
        $objProcedimentoAndamentoDTO = new ProcedimentoAndamentoDTO();
        $objProcedimentoAndamentoDTO->retDblIdAndamento();
        $objProcedimentoAndamentoDTO->setStrNumeroRegistro($parStrNumeroRegistro);
        $objProcedimentoAndamentoDTO->setDblIdTramite($parNumIdentificacaoTramite);
        $objProcedimentoAndamentoDTO->setNumTarefa($numIdTarefa);
        $objProcedimentoAndamentoDTO->setNumMaxRegistrosRetorno(1);
        
        $objProcedimentoAndamentoBD = new ProcedimentoAndamentoBD($this->getObjInfraIBanco());
        $objProcedimentoAndamentoDTORet = $objProcedimentoAndamentoBD->consultar($objProcedimentoAndamentoDTO);
        
        $this->gravarLogDebug("Sincronizando o recebimento de processos concorrentes...", 4);
        $objProcedimentoAndamentoDTO = $objProcedimentoAndamentoBD->bloquear($objProcedimentoAndamentoDTORet) ? isset($objProcedimentoAndamentoDTORet) : false;
        $this->gravarLogDebug("Liberando processo concorrente de recebimento de processo ...", 4);
        return $objProcedimentoAndamentoDTO;
    }
    
    /**
    * Verifica se existe documentos com pend�ncia de download de seus componentes digitais
    * @param  [type] $parNumIdProcedimento        Identificador do processo
    * @param  [type] $parNumIdDocumento           Identificador do documento
    * @param  [type] $parStrHashComponenteDigital Hash do componente digital
    * @return [type]                              Indica��o se existe pend�ncia ou n�o
    */
    private function documentosPendenteRegistro($parNumIdProcedimento, $parNumIdDocumento=null, $parStrHashComponenteDigital=null)
    {
        //Valida se algum documento ficou sem seus respectivos componentes digitais
        $sql = "select doc.id_documento as id_documento, comp.hash_conteudo as hash_conteudo
        from procedimento proced join documento doc on (doc.id_procedimento = proced.id_procedimento)
        join protocolo prot_doc on (doc.id_documento = prot_doc.id_protocolo)
        left join md_pen_componente_digital comp on (comp.id_documento = doc.id_documento)
        where proced.id_procedimento = $parNumIdProcedimento
        and prot_doc.sta_protocolo = 'R'
        and prot_doc.sta_estado <> " . ProtocoloRN::$TE_DOCUMENTO_CANCELADO . "
        and not exists (select 1 from anexo where anexo.id_protocolo = prot_doc.id_protocolo) ";
        
        //Adiciona filtro adicional para verificar pelo identificador do documento, caso par�metro tenha sido informado
        if(!is_null($parNumIdDocumento)){
            $sql .= " and doc.id_documento = $parNumIdDocumento";
        }
        
        $recordset = $this->getObjInfraIBanco()->consultarSql($sql);
        
        $bolDocumentoPendente = !empty($recordset);
        
        //Verifica especificamente um determinado hash atrav�s da verifica��o do hash do componente, caso par�metro tenha sido informado
        if($bolDocumentoPendente && !is_null($parStrHashComponenteDigital)) {
            foreach ($recordset as $item) {
                if(!is_null($item['hash_conteudo']) && $item['hash_conteudo'] === $parStrHashComponenteDigital){
                    $bolDocumentoPendente = true;
                    return $bolDocumentoPendente;
                }
            }
            
            $bolDocumentoPendente = false;
        }
        
        return $bolDocumentoPendente;
    }
    
    
    /**
    * M�todo responsav�l por obter o tamanho do componente pendente de recebimento
    * @author Josinaldo J�nior <josinaldo.junior@basis.com.br>
    * @param $parObjProtocolo
    * @param $parComponentePendente
    * @return $tamanhoComponentePendende
    */
    private function obterTamanhoComponenteDigitalPendente($parObjProtocolo, $parComponentePendente)
    {
        //Obt�m os documentos do protocolo em um array
        $arrObjDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        
        //Percorre os documentos e compoenntes para pegar o tamanho em bytes do componente
        foreach ($arrObjDocumentos as $objDocumento){
            $arrObjComponentesDigitais = ProcessoEletronicoRN::obterComponentesDigitaisDocumento($objDocumento);
            foreach ($arrObjComponentesDigitais as $objComponentesDigital){
                if($objComponentesDigital->hash->_ == $parComponentePendente){
                    $tamanhoComponentePendende = $objComponentesDigital->tamanhoEmBytes; break;
                }
            }
        }
        return $tamanhoComponentePendende;
    }
    
    
    /**
    * M�todo respons�vel por realizar o recebimento do componente digital particionado, de acordo com o parametro (PEN_TAMANHO_MAXIMO_DOCUMENTO_EXPEDIDO)
    * @author Josinaldo J�nior <josinaldo.junior@basis.com.br>
    * @param $componentePendente
    * @param $nrTamanhoBytesMaximo
    * @param $nrTamanhoBytesArquivo
    * @param $nrTamanhoMegasMaximo
    * @param $numComponentes
    * @param $parNumIdentificacaoTramite
    * @param $objTramite
    * @return AnexoDTO
    * @throws InfraException
    */
    private function receberComponenenteDigitalParticionado($componentePendente, $nrTamanhoBytesMaximo, $nrTamanhoBytesArquivo, $nrTamanhoMegasMaximo, $numComponente,
    $parNumIdentificacaoTramite, $objTramite, $arrObjComponenteDigitalIndexado)
    {
        $receberComponenteDigitalRN = new ReceberComponenteDigitalRN();
        
        $qtdPartes = ceil(($nrTamanhoBytesArquivo / pow(1024, 2)) / $nrTamanhoMegasMaximo);
        $inicio = 0;
        $fim    = $nrTamanhoBytesMaximo;
        
        //Realiza o recebimento do arquivo em partes
        for ($i = 1; $i <= $qtdPartes; $i++)
        {
            $objIdentificacaoDaParte = new stdClass();
            $objIdentificacaoDaParte->inicio = $inicio;
            $objIdentificacaoDaParte->fim = ($i == $qtdPartes) ? $nrTamanhoBytesArquivo : $fim;
            
            $numTempoInicialDownload = microtime(true);
            $objComponenteDigital = $this->objProcessoEletronicoRN->receberComponenteDigital($parNumIdentificacaoTramite, $componentePendente, $objTramite->protocolo, $objIdentificacaoDaParte);
            $numTempoTotalDownload = round(microtime(true) - $numTempoInicialDownload, 2);
            $numTamanhoArquivoKB = round(strlen($objComponenteDigital->conteudoDoComponenteDigital) / 1024, 2);
            $numVelocidade = round($numTamanhoArquivoKB / max([$numTempoTotalDownload, 1]), 2);
            $this->gravarLogDebug("Recuperado parte $i de $qtdPartes do componente digital $numComponente. Taxa de transfer�ncia para $numTamanhoArquivoKB kb: {$numVelocidade} kb/s", 6);
            
            //Verifica se � a primeira execu��o do la�o, se for cria do arquivo na pasta temporaria, sen�o incrementa o conteudo no arquivo
            if($i == 1) {
                $infoAnexoRetornado = $receberComponenteDigitalRN->copiarComponenteDigitalPastaTemporaria($arrObjComponenteDigitalIndexado[$componentePendente], $objComponenteDigital);
                $objAnexoDTO = $infoAnexoRetornado;
            }else{
                //Incrementa arquivo na pasta TEMP
                $numIdAnexo = $objAnexoDTO->getNumIdAnexo();
                $strConteudoCodificado = $objComponenteDigital->conteudoDoComponenteDigital;
                
                $fp = fopen(DIR_SEI_TEMP.'/'.$numIdAnexo,'a');
                fwrite($fp,$strConteudoCodificado);
                fclose($fp);
            }
            
            $inicio = ($nrTamanhoBytesMaximo * $i);
            $fim += $nrTamanhoBytesMaximo;
        }
        
        //Atualiza tamanho total do anexo no banco de dados
        $objAnexoDTO->setNumTamanho($nrTamanhoBytesArquivo);
        
        return $objAnexoDTO;
    }
    
    
    private function indexarComponenteDigitaisDoProtocolo($parObjProtocolo)
    {
        $resultado = array();
        $arrObjDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);
        foreach($arrObjDocumentos as $objDocumento){
            if(isset($objDocumento->componenteDigital) && !is_array($objDocumento->componenteDigital)){
                $objDocumento->componenteDigital = array($objDocumento->componenteDigital);
            }
            foreach($objDocumento->componenteDigital as $objComponente){
                $resultado[$objComponente->hash->_] = $objComponente;
            }
        }
        return $resultado;
    }
    
    
    /**
    * Valida��o de p�s condi��es para garantir que nenhuma inconsist�ncia foi identificada no recebimento do processo
    *
    * @param  [type] $parObjMetadadosProcedimento Metadados do Protocolo
    * @param  [type] $parObjProcedimentoDTO       Dados do Processo gerado no recebimento
    */
    private function validarPosCondicoesTramite($parObjMetadadosProcedimento, $parObjProcedimentoDTO)
    {
        $strMensagemPadrao = "Inconsist�ncia identificada no recebimento de processo: \n";
        $strMensagemErro = "";
        
        //Valida se metadados do tr�mite e do protocolo foram identificado
        if(is_null($parObjMetadadosProcedimento)){
            $strMensagemErro = "- Metadados do tr�mite n�o identificado. \n";
        }
        
        //Valida se metadados do tr�mite e do protocolo foram identificado
        if(is_null($parObjProcedimentoDTO)){
            $strMensagemErro = "- Dados do processo n�o identificados \n";
        }
        
        //Valida se algum documento ficou sem seus respectivos componentes digitais
        if($this->documentosPendenteRegistro($parObjProcedimentoDTO->getDblIdProcedimento())){
            $strProtocoloFormatado = $parObjProcedimentoDTO->getStrProtocoloProcedimentoFormatado();
            $strMensagemErro = "- Componente digital de pelo menos um dos documentos do processo [$strProtocoloFormatado] n�o pode ser recebido. \n";
        }
        
        if(!InfraString::isBolVazia($strMensagemErro)){
            throw new InfraException($strMensagemPadrao . $strMensagemErro);
        }
    }
    
    private function gravarLogDebug($parStrMensagem, $parNumIdentacao=0, $parBolLogTempoProcessamento=true)
    {
        $this->objPenDebug->gravar($parStrMensagem, $parNumIdentacao, $parBolLogTempoProcessamento);
    }
    
    private function substituirDestinoParaUnidadeReceptora($parObjMetadadosTramite, $parNumIdentificacaoTramite)
    {
        if (isset($parObjMetadadosTramite->metadados->unidadeReceptora)) {
            $unidadeReceptora = $parObjMetadadosTramite->metadados->unidadeReceptora;
            $this->destinatarioReal = $parObjMetadadosTramite->metadados->destinatario;
            $parObjMetadadosTramite->metadados->destinatario->identificacaoDoRepositorioDeEstruturas = $unidadeReceptora->identificacaoDoRepositorioDeEstruturas;
            $parObjMetadadosTramite->metadados->destinatario->numeroDeIdentificacaoDaEstrutura = $unidadeReceptora->numeroDeIdentificacaoDaEstrutura;
            $numUnidadeReceptora = $unidadeReceptora->numeroDeIdentificacaoDaEstrutura;
            $this->gravarLogDebug("Atribuindo unidade receptora $numUnidadeReceptora para o tr�mite $parNumIdentificacaoTramite", 4);
        }
    }
    
    /**
    * M�todo respons�vel pelo desmembramento de processos anexados
    * 
    * M�todo respons�vel por desmembrar os metadados do processo recebido caso ele possua outros processos anexados
    * O desmembramento � necess�rio para que o processo possa ser recriado na mesma estrutura original, ou seja, em v�rios 
    * processos diferentes, um anexado ao outro
    * 
    * @param object $parObjProtocolo
    * 
    * @return list($objProtocoloPrincipal, $arrProtocolosAnexados)
    */
    private function desmembrarProcessosAnexados($parObjProtocolo) 
    {
        $arrObjDocumentos = ProcessoEletronicoRN::obterDocumentosProtocolo($parObjProtocolo);

        // Fun��o an�nima de identifica��o se um determinado documento faz parte de um processo anexado
        $funcDocumentoFoiAnexado = function($parObjDocumento) use ($parObjProtocolo){
            return (
                isset($parObjDocumento->protocoloDoProcessoAnexado) && 
                !empty($parObjDocumento->protocoloDoProcessoAnexado) && 
                $parObjProtocolo->protocolo != $parObjDocumento->protocoloDoProcessoAnexado
            );
        };

        // Verifica se existe algum processo anexado, retornando a refer�ncia original do processo caso n�o exista
        $bolExisteProcessoAnexado = array_reduce($parObjProtocolo->documento, function($bolExiste, $objDoc) use ($funcDocumentoFoiAnexado){
            return $bolExiste || $funcDocumentoFoiAnexado($objDoc);
        });
        
        if(!$bolExisteProcessoAnexado){
            return $parObjProtocolo;
        }

        $arrObjRefProcessosAnexados = array();
        $objProcessoPrincipal = clone $parObjProtocolo;
        $objProcessoPrincipal->documento = array();
        $arrObjDocumentosOrdenados = $arrObjDocumentos;
        usort($arrObjDocumentosOrdenados, array("ReceberProcedimentoRN", "comparacaoOrdemDocumentos"));
                
        // Agrupamento dos documentos por processo
        foreach ($arrObjDocumentosOrdenados as $objDocumento) {
            $bolDocumentoAnexado = $funcDocumentoFoiAnexado($objDocumento);            
            $strProtocoloProcAnexado = ($bolDocumentoAnexado) ? $objDocumento->protocoloDoProcessoAnexado : $objProcessoPrincipal->protocolo;
            
            // Cria uma nova presenta��o para o processo anexado identico ao processo principal
            // As informa��es do processo anexado n�o s�o consideradas pois n�o existem metadados no modelo do PEN, 
            // existe apenas o n�mero do protocolo de refer�ncia
            if($bolDocumentoAnexado && !array_key_exists($strProtocoloProcAnexado, $arrObjRefProcessosAnexados)){
                $objProcessoAnexado = clone $objProcessoPrincipal;
                $objProcessoAnexado->documento = array();
                $objProcessoAnexado->ordem = count($objProcessoPrincipal->documento) + 1; $objProcessoAnexado->protocolo = $strProtocoloProcAnexado;
                $objProcessoPrincipal->documento[] = $objProcessoAnexado;
                $arrObjRefProcessosAnexados[$strProtocoloProcAnexado] = $objProcessoAnexado;
            }
            
            $objProcessoDoDocumento = ($bolDocumentoAnexado) ? $arrObjRefProcessosAnexados[$strProtocoloProcAnexado] : $objProcessoPrincipal;
            $objDocumentoReposicionado = clone $objDocumento;            
            $objDocumentoReposicionado->ordem = count($objProcessoDoDocumento->documento) + 1;
            $objProcessoDoDocumento->documento[] = $objDocumentoReposicionado;
        }    
        
        return $objProcessoPrincipal;
    }
}
