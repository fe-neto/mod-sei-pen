<?php

//TO-DO...botao de delete e alterar

require_once DIR_SEI_WEB.'/SEI.php';

session_start();

$objPagina = PaginaSEI::getInstance();
$objBanco = BancoSEI::getInstance();
$objSessao = SessaoSEI::getInstance();
$objDebug = InfraDebug::getInstance();

$PaginaUrl=PenUtils::acaoURL($_GET['acao'],$_GET['acao_origem'],$_GET['acao_retorno']);

try {

    $objDebug->setBolLigado(false);
    $objDebug->setBolDebugInfra(true);
    $objDebug->limpar();

    $objSessao->validarLink();
    $objSessao->validarPermissao(PEN_RECURSO_ATUAL);


    //--------------------------------------------------------------------------

    $arrComandos = array();
    $arrComandos[] = '<button type="button" accesskey="P" onclick="onClickBtnPesquisar();" id="btnPesquisar" value="Pesquisar" class="infraButton"><span class="infraTeclaAtalho">P</span>esquisar</button>';
    $arrComandos[] = '<button type="button" value="Novo" onclick="onClickBtnNovo()" class="infraButton"><span class="infraTeclaAtalho">N</span>ovo</button>';
    $arrComandos[] = '<button type="button" value="Excluir" onclick="onClickBtnExcluir()" class="infraButton"><span class="infraTeclaAtalho">E</span>xcluir</button>';
    $arrComandos[] = '<button type="button" accesskey="I" id="btnImprimir" value="Imprimir" onclick="infraImprimirTabela();" class="infraButton"><span class="infraTeclaAtalho">I</span>mprimir</button>';

    //--------------------------------------------------------------------------
    // DTO de paginao

    $objPenRelHipoteseLegalDTO=$data["mapeamento"]["dtoRelacaoHipoteses"];

    //--------------------------------------------------------------------------
    // // Filtragem
   
    $arrMapIdBarramento=$data["hipoteseRemota"];
    $arrMapIdHipoteseLegal = $data["hipoteseLocal"];

    $objPagina->prepararOrdenacao($objPenRelHipoteseLegalDTO, 'IdMap', InfraDTO::$TIPO_ORDENACAO_ASC);
    $objPagina->prepararPaginacao($objPenRelHipoteseLegalDTO);
    $objPagina->processarPaginacao($objPenRelHipoteseLegalDTO);

    $arrObjPenRelHipoteseLegalDTO = $data["mapeamento"]["arrRelacaoHipoteses"];
    $numRegistros = count($arrObjPenRelHipoteseLegalDTO);

    if(!empty($arrObjPenRelHipoteseLegalDTO)){

        $strResultado = '';

        $strResultado .= '<table width="99%" class="infraTable">'."\n";
        $strResultado .= '<caption class="infraCaption">'.$objPagina->gerarCaptionTabela(PEN_PAGINA_TITULO, $numRegistros).'</caption>';

        $strResultado .= '<tr>';
        $strResultado .= '<th class="infraTh" width="1%">'.$objPagina->getThCheck().'</th>'."\n";
        $strResultado .= '<th class="infraTh" width="35%">Hipótese Legal SEI - '.$objSessao->getStrSiglaOrgaoUnidadeAtual().'</th>'."\n";
        $strResultado .= '<th class="infraTh" width="35%">Hipótese Legal PEN</th>'."\n";
        $strResultado .= '<th class="infraTh" width="14%">Ações</th>'."\n";
        $strResultado .= '</tr>'."\n";
        $strCssTr = '';

        $index = 0;
        foreach($arrObjPenRelHipoteseLegalDTO as $objPenRelHipoteseLegalDTO) {

            $strCssTr = ($strCssTr == 'infraTrClara') ? 'infraTrEscura' : 'infraTrClara';

            $strResultado .= '<tr class="'.$strCssTr.'">';
            $strResultado .= '<td>'.$objPagina->getTrCheck($index, $objPenRelHipoteseLegalDTO->getDblIdMap(), '').'</td>';
            $strResultado .= '<td>'.$arrMapIdHipoteseLegal[$objPenRelHipoteseLegalDTO->getNumIdHipoteseLegal()].'</td>';
            $strResultado .= '<td>'.$arrMapIdBarramento[$objPenRelHipoteseLegalDTO->getNumIdBarramento()].'</td>';
            $strResultado .= '<td align="center">';

            //TODO
            if($objSessao->verificarPermissao('pen_map_hipotese_legal_envio_alterar')) {
                $strResultado .= '<a href="'.$objSessao->assinarLink('controlador.php?acao='.PEN_RECURSO_BASE.'_cadastrar&acao_origem='.$_GET['acao_origem'].'&acao_retorno='.$_GET['acao'].'&'.PEN_PAGINA_GET_ID.'='.$objPenRelHipoteseLegalDTO->getDblIdMap()).'"><img src="imagens/alterar.gif" title="Alterar Mapeamento" alt="Alterar Mapeamento" class="infraImg"></a>';
            }
            //TODO
            if($objSessao->verificarPermissao('pen_map_hipotese_legal_envio_excluir')) {
                $strResultado .= '<a href="#" onclick="onCLickLinkDelete( this, ' . $objPenRelHipoteseLegalDTO->getDblIdMap() .' )"><img src="imagens/excluir.gif" title="Excluir Mapeamento" alt="Excluir Mapeamento" class="infraImg"></a>';
            }

            $strResultado .= '</td>';
            $strResultado .= '</tr>'."\n";

            $index++;
        }
        $strResultado .= '</table>';
    }
}
catch(InfraException $e){

    print '<pre>';
    print_r($e);
    print '</pre>';
    exit(0);
}


$objPagina->montarDocType();
$objPagina->abrirHtml();
$objPagina->abrirHead();
$objPagina->montarMeta();
$objPagina->montarTitle(':: '.$objPagina->getStrNomeSistema().' - '.PEN_PAGINA_TITULO.' ::');
$objPagina->montarStyle();
?>
<link rel="stylesheet" href=<?=PenController::includeCss("HipoteseLegalView")?>   >

<?php $objPagina->montarJavaScript(); ?>
<script type="text/javascript"src=<?=PenController::includeJs("HipoteseLegalView")?> > </script>

<?php
$objPagina->fecharHead();
$objPagina->abrirBody(PEN_PAGINA_TITULO,'onload="inicializar();"');
?>


<p id="urlCadastro" data-urlSei="<?php print PenUtils::acaoURL(PEN_RECURSO_BASE.'_cadastrar', $_GET['acao_origem'],$_GET['acao_origem']) ?>"/>


<form id="frmAcompanharEstadoProcesso" method="post" action="<?php print $PaginaUrl ?>">

    <?php $objPagina->montarBarraComandosSuperior($arrComandos); ?>
    <?php $objPagina->abrirAreaDados('40px'); ?>

        <label for="id_hipotese_legal" class="infraLabelObrigatorio input-label-first">Hipótese Legal SEI - <?php print $objSessao->getStrSiglaOrgaoUnidadeAtual(); ?>:</label>
        <select name="id_hipotese_legal" class="infraSelect input-field-first"<?php if($bolSomenteLeitura): ?>  disabled="disabled" readonly="readonly"<?php endif; ?>>
            <?php  print InfraINT::montarSelectArray('', 'Selecione', $data["filtroLocal"], $arrMapIdHipoteseLegal); ?>

        </select>

        <label for="id_barramento" class="infraLabelObrigatorio input-label-second">Hipótese Legal PEN:</label>
        <select name="id_barramento" class="infraSelect input-field-second"<?php if($bolSomenteLeitura): ?> disabled="disabled" readonly="readonly"<?php endif; ?>>
            <?php print InfraINT::montarSelectArray('', 'Selecione', $data["filtroBarramento"], $arrMapIdBarramento); ?>
        </select>


    <?php $objPagina->fecharAreaDados(); ?>

    <?php if($numRegistros > 0): ?>
        <?php $objPagina->montarAreaTabela($strResultado, $numRegistros); ?>
    <?php else: ?>
        <div style="clear:both"></div>
        <p>Nenhum mapeamento foi encontrado</p>
    <?php endif; ?>
</form>
<?php $objPagina->fecharBody(); ?>
<?php $objPagina->fecharHtml(); ?>
