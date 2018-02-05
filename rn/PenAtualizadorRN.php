<?php

/**
 * Atualizador abstrato para sistema do SEI para instalar/atualizar o m�dulo PEN
 * 
 * @autor Join Tecnologia
 */
abstract class PenAtualizadorRN extends InfraRN {

    protected $sei_versao;

    /**
     * @var string Vers�o m�nima requirida pelo sistema para instala��o do PEN
     */
    protected $versaoMinRequirida;

    /**
     * @var InfraIBanco Inst�ncia da classe de persist�ncia com o banco de dados
     */
    protected $objBanco;

    /**
     * @var InfraMetaBD Inst�ncia do metadata do banco de dados
     */
    protected $objMeta;

    /**
     * @var InfraDebug Inst�ncia do debuger
     */
    protected $objDebug;

    /**
     * @var integer Tempo de execu��o do script
     */
    protected $numSeg = 0;
    
    protected $objInfraBanco ;

    protected function inicializarObjInfraIBanco() {
        
        if (empty($this->objInfraBanco)) {
            $this->objInfraBanco = BancoSEI::getInstance();
            $this->objInfraBanco->abrirConexao();
        }
        
        return $this->objInfraBanco;
    }

    /**
     * Inicia a conex�o com o banco de dados
     */
    protected function inicializarObjMetaBanco() {
        if (empty($this->objMeta)) {
            $this->objMeta = new PenMetaBD($this->inicializarObjInfraIBanco());
        }
        return $this->objMeta;
    }

    /**
     * Adiciona uma mensagem ao output para o usu�rio
     * 
     * @return null
     */
    protected function logar($strMsg) {
        $this->objDebug->gravar($strMsg);
    }

    /**
     * Inicia o script criando um contator interno do tempo de execu��o
     * 
     * @return null
     */
    protected function inicializar($strTitulo) {

        $this->numSeg = InfraUtil::verificarTempoProcessamento();

        $this->logar($strTitulo);
    }

    /**
     * Finaliza o script informando o tempo de execu��o.
     * 
     * @return null
     */
    protected function finalizar() {

        $this->logar('TEMPO TOTAL DE EXECUCAO: ' . InfraUtil::verificarTempoProcessamento($this->numSeg) . ' s');

        $this->objDebug->setBolLigado(false);
        $this->objDebug->setBolDebugInfra(false);
        $this->objDebug->setBolEcho(false);

        print PHP_EOL;
        die();
    }

    /**
     * Construtor
     * 
     * @param array $arrArgs Argumentos enviados pelo script
     */
    public function __construct() {
        
        parent::__construct();
        ini_set('max_execution_time', '0');
        ini_set('memory_limit', '-1');
        @ini_set('zlib.output_compression', '0');
        @ini_set('implicit_flush', '1');
        ob_implicit_flush();
        
        $this->inicializarObjInfraIBanco();
        $this->inicializarObjMetaBanco();

        $this->objDebug = InfraDebug::getInstance();
        $this->objDebug->setBolLigado(true);
        $this->objDebug->setBolDebugInfra(true);
        $this->objDebug->setBolEcho(true);
        $this->objDebug->limpar();
    }

}
