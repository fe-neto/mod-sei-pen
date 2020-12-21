<?
/**
 *
 */
try {
    require_once DIR_SEI_WEB.'/SEI.php';

    session_start();

    define('PEN_RECURSO_ATUAL', 'pen_parametros_configuracao');
    define('PEN_PAGINA_TITULO', 'Par�metros de Configura��o do M�dulo de Tramita��es PEN');

    $objPagina = PaginaSEI::getInstance();
    $objBanco = BancoSEI::getInstance();
    $objSessao = SessaoSEI::getInstance();

    $o = new PenRelHipoteseLegalEnvioRN();
    $os = new PenRelHipoteseLegalRecebidoRN();

    $objSessao->validarPermissao('pen_parametros_configuracao');

    $objPenParametroDTO = new PenParametroDTO();
    $objPenParametroDTO->retTodos();
    $objPenParametroDTO->setNumSequencia(null, InfraDTO::$OPER_DIFERENTE);
    $objPenParametroDTO->setOrdNumSequencia(InfraDTO::$TIPO_ORDENACAO_ASC);

    $objPenParametroRN = new PenParametroRN();
    $retParametros = $objPenParametroRN->listar($objPenParametroDTO);

    /* Busca os dados para montar dropdown ( TIPO DE PROCESSO EXTERNO ) */
    $objTipoProcedimentoDTO = new TipoProcedimentoDTO();
    $objTipoProcedimentoDTO->retNumIdTipoProcedimento();
    $objTipoProcedimentoDTO->retStrNome();
    $objTipoProcedimentoDTO->setOrdStrNome(InfraDTO::$TIPO_ORDENACAO_ASC);
    $objTipoProcedimentoRN = new TipoProcedimentoRN();
    $arrObjTipoProcedimentoDTO = $objTipoProcedimentoRN->listarRN0244($objTipoProcedimentoDTO);

    /* Busca os dados para montar dropdown ( UNIDADE GERADORA DOCUMENTO RECEBIDO ) */
    $objUnidadeDTO = new UnidadeDTO();
    $objUnidadeDTO->retNumIdUnidade();
    $objUnidadeDTO->retStrSigla();
    $objUnidadeDTO->setOrdStrSigla(InfraDTO::$TIPO_ORDENACAO_ASC);
    $objUnidadeRN = new UnidadeRN();
    $arrObjUnidade = $objUnidadeRN->listarRN0127($objUnidadeDTO);

    if ($objPenParametroDTO===null){
        throw new InfraException("Registros n�o encontrados.");
    }
    
    $objPenParametroRN = new PenParametroRN();
    $objPenParametroDTO=new PenParametroDTO();
    $objPenParametroDTO->setStrNome("PHP_INI_WEB");
    $objPenParametroDTO->retStrValor();
    $phpIniWeb=$objPenParametroRN->consultar($objPenParametroDTO);
    $phpIniWeb=$phpIniWeb==null? null : $phpIniWeb->getStrValor();
    
    if($phpIniWeb==null){
        $phpIniWeb=php_ini_loaded_file();
        $objPenParametroDTO = new PenParametroDTO();
        $objPenParametroDTO->setStrNome("PHP_INI_WEB");
        $objPenParametroDTO->setStrValor($phpIniWeb);
        $objPenParametroDTO->setStrDescricao("php.ini utilizado pelo servidor web do �rgao");
        $objPenParametroRN = new PenParametroRN();
        $objPenParametroRN->cadastrar($objPenParametroDTO);

    }elseif($phpIniWeb!=php_ini_loaded_file()){

        $objPenParametroDTO = new PenParametroDTO();
        $objPenParametroDTO->setStrNome("PHP_INI_WEB");
        $objPenParametroDTO->setStrValor(php_ini_loaded_file());
        $objPenParametroDTO->setStrDescricao("php.ini utilizado pelo servidor web do �rgao");
        $objPenParametroRN = new PenParametroRN();
        $objPenParametroRN->alterar($objPenParametroDTO);
    }


    switch ($_GET['acao']) {
        case 'pen_parametros_configuracao_salvar':
            try {
                $objPenParametroRN = new PenParametroRN();

                if (!empty(count($_POST['parametro']))) {
                    foreach ($_POST['parametro'] as $nome => $valor) {
                        $objPenParametroDTO = new PenParametroDTO();
                        $objPenParametroDTO->setStrNome($nome);
                        $objPenParametroDTO->retStrNome();

                        if($objPenParametroRN->contar($objPenParametroDTO) > 0) {
                            $objPenParametroDTO->setStrValor(trim($valor));
                            $objPenParametroRN->alterar($objPenParametroDTO);
                        }
                    }
                }
            } catch (Exception $e) {
                $objPagina->processarExcecao($e);
            }
            header('Location: ' . $objSessao->assinarLink('controlador.php?acao=' . $_GET['acao_origem'] . '&acao_origem=' . $_GET['acao']));
            die;

        case 'pen_parametros_configuracao':
            $strTitulo = 'Par�metros de Configura��o do M�dulo de Tramita��es PEN';
            break;

        default:
            throw new InfraException("A��o '" . $_GET['acao'] . "' n�o reconhecida.");
    }

} catch (Exception $e) {
    $objPagina->processarExcecao($e);
}

//Monta os bot�es do topo
if ($objSessao->verificarPermissao('pen_parametros_configuracao_alterar')) {
    $arrComandos[] = '<button type="submit" id="btnSalvar" value="Salvar" class="infraButton"><span class="infraTeclaAtalho">S</span>alvar</button>';
}
$arrComandos[] = '<button type="button" id="btnCancelar" value="Cancelar" onclick="location.href=\'' . $objPagina->formatarXHTML($objSessao->assinarLink('controlador.php?acao=pen_parametros_configuracao&acao_origem=' . $_GET['acao'])) . '\';" class="infraButton"><span class="infraTeclaAtalho">C</span>ancelar</button>';

$objPagina->montarDocType();
$objPagina->abrirHtml();
$objPagina->abrirHead();
$objPagina->montarMeta();
$objPagina->montarTitle($objPagina->getStrNomeSistema() . ' - ' . $strTitulo);
$objPagina->montarStyle();
$objPagina->abrirStyle();
?>
.input-field {
    width: 45%;
    margin-bottom: 20px;
    margin-top: 2px;
}

.erro_pen{
    width: 15px;
    height: 15px;
    margin-left: 5px;
}

<?
$objPagina->fecharStyle();
$objPagina->montarJavaScript();
$objPagina->abrirJavaScript();
?>

function inicializar(){
    if ('<?= $_GET['acao'] ?>'=='pen_parametros_configuracao_selecionar'){
        infraReceberSelecao();
        document.getElementById('btnFecharSelecao').focus();
    }else{
        document.getElementById('btnCancelar').focus();
    }
    infraEfeitoImagens();
    infraEfeitoTabelas();
}

function OnSubmitForm() {

    var camposValidacao = [
        {'id': 'PEN_ID_REPOSITORIO_ORIGEM', 'mensagem': 'Selecione um Reposit�rio de Estruturas de Origem.'},
        {'id': 'PEN_TIPO_PROCESSO_EXTERNO', 'mensagem': 'Selecione um Tipo de Processo Externo.'},
        {'id': 'PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO', 'mensagem': 'Selecione uma Unidade Geradora de Processo e Documento Recebido.'},
        {'id': 'PEN_ENVIA_EMAIL_NOTIFICACAO_RECEBIMENTO', 'mensagem': 'Selecione uma op��o para Envio de E-mail de Notifica��o de Recebimento.'},
    ];

    return camposValidacao.every(validarPreenchimentoCampo);
}

function validarPreenchimentoCampo(campoValidacao){
    var valido = infraSelectSelecionado(campoValidacao.id);

    if (!valido) {
        alert(campoValidacao.mensagem);
        document.getElementById(campoValidacao.id).focus();
    }

    return valido;
}

<?
$objPagina->fecharJavaScript();
$objPagina->fecharHead();
$objPagina->abrirBody($strTitulo, 'onload="inicializar();"');
?>

<form id="frmInfraParametroCadastro" method="post" onsubmit="return OnSubmitForm();" action="<?=$objSessao->assinarLink('controlador.php?acao='.$_GET['acao'].'_salvar&acao_origem='.$_GET['acao'])?>">
    <?
    $objPagina->montarBarraComandosSuperior($arrComandos);
    foreach ($retParametros as $parametro) {

        echo '<div class="container">';
        //Esse par�metro n�o aparece, por j� existencia de uma tela s� para altera��o do pr�prio.
        if ($parametro->getStrNome() != 'HIPOTESE_LEGAL_PADRAO') {
            ?> <label id="lbl<?= PaginaSEI::tratarHTML($parametro->getStrNome()); ?>" for="txt<?= PaginaSEI::tratarHTML($parametro->getStrNome()); ?>" accesskey="N" class="infraLabelObrigatorio"><?=  PaginaSEI::tratarHTML($parametro->getStrDescricao()); ?>:</label><br> <?php
        }

        //Constr�i o campo de valor
        switch ($parametro->getStrNome()) {

            case 'PEN_ID_REPOSITORIO_ORIGEM':
                try {
                    $objExpedirProcedimentosRN = new ExpedirProcedimentoRN();
                    $repositorios = $objExpedirProcedimentosRN->listarRepositoriosDeEstruturas();
                    $idRepositorioSelecionado = (!is_null($parametro->getStrValor())) ? $parametro->getStrValor() : '';
                    echo '<select id="PEN_ID_REPOSITORIO_ORIGEM" name="parametro[PEN_ID_REPOSITORIO_ORIGEM]" class="infraSelect input-field">';
                            echo InfraINT::montarSelectArray('null', '&nbsp;', $idRepositorioSelecionado, $repositorios);
                    echo '</select>';
                } catch (Exception $e) {
                    // Caso ocorra alguma falha na obten��o de dados dos servi�os do PEN, apresenta estilo de campo padr�o
                    echo '<input type="text" id="PARAMETRO_PEN_ID_REPOSITORIO_ORIGEM" name="parametro[PEN_ID_REPOSITORIO_ORIGEM]" class="infraText input-field load_error" value="'.$objPagina->tratarHTML($parametro->getStrValor()).'" onkeypress="return infraMascaraTexto(this,event);" tabindex="'.$objPagina->getProxTabDados().'" maxlength="100" />';
                    echo '<img class="erro_pen" src="imagens/sei_erro.png" title="N�o foi poss�vel carregar os Reposit�rios de Estruturas dispon�veis no PEN devido � falha de acesso ao Barramento de Servi�os. O valor apresenta��o no campo � o c�digo do reposit�rio configurado anteriormente">';
                }

                break;


            case 'PEN_TIPO_PROCESSO_EXTERNO':
                echo '<select id="PEN_TIPO_PROCESSO_EXTERNO" name="parametro[PEN_TIPO_PROCESSO_EXTERNO]" class="infraText input-field" >';
                echo InfraINT::montarSelectArrInfraDTO('null', '&nbsp;', $parametro->getStrValor(), $arrObjTipoProcedimentoDTO, 'IdTipoProcedimento', 'Nome');
                echo '<select>';
                break;

            case 'PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO':
                echo '<select id="PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO" name="parametro[PEN_UNIDADE_GERADORA_DOCUMENTO_RECEBIDO]" class="infraText input-field" >';
                echo InfraINT::montarSelectArrInfraDTO('null', '&nbsp;', $parametro->getStrValor(), $arrObjUnidade, 'IdUnidade', 'Sigla');
                echo '<select>';
                break;

            case 'PEN_ENVIA_EMAIL_NOTIFICACAO_RECEBIMENTO':
                echo '<select id="PEN_ENVIA_EMAIL_NOTIFICACAO_RECEBIMENTO" name="parametro[PEN_ENVIA_EMAIL_NOTIFICACAO_RECEBIMENTO]" class="infraText input-field" >';
                echo '    <option value="S" ' . ($parametro->getStrValor() == 'S' ? 'selected="selected"' : '') . '>Sim</option>';
                echo '    <option value="N" ' . ($parametro->getStrValor() == 'N' ? 'selected="selected"' : '') . '>N�o</option>';
                echo '<select>';
                break;

            default:
                echo '<input type="text" id="PARAMETRO_'.$parametro->getStrNome().'" name="parametro['.$parametro->getStrNome().']" class="infraText input-field" value="'.$objPagina->tratarHTML($parametro->getStrValor()).'" onkeypress="return infraMascaraTexto(this,event);" tabindex="'.$objPagina->getProxTabDados().'" maxlength="100" />';
                break;
        }

        echo '</div>';
    }

?>
</form>

    <label id="lblPhpIni" for="phpIniWeb" class="infraLabelObrigatorio">Caminho do php.ini utilizado (apenas para consulta):</label><br> 
    <?php
    echo '<input type="text" id="phpIniWeb" name="phpIniWeb" class="infraText infraReadOnly " value="'.$phpIniWeb .'" readonly />';
    
    ?>

<?
$objPagina->fecharBody();
$objPagina->fecharHtml();
?>
