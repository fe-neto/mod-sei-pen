<?php

require_once DIR_SEI_WEB . '/SEI.php';

/**
 * Description of PenHipoteseLegalBD
 *
 *
 */
class PenHipoteseLegalv2BD extends InfraBD
{


    //da para fazer TUnit tb
    public function __construct(InfraIBanco $objInfraIBanco)
    {

        parent::__construct($objInfraIBanco);
    }


    public function retArrHipoteseLegalLocal()
    {

        $objHipoteseLegalDTO = new HipoteseLegalDTO();
        $objHipoteseLegalDTO->setDistinct(true);
        $objHipoteseLegalDTO->setStrStaNivelAcesso(ProtocoloRN::$NA_RESTRITO); //Restrito
        $objHipoteseLegalDTO->setStrSinAtivo('S');
        $objHipoteseLegalDTO->setOrdStrNome(InfraDTO::$TIPO_ORDENACAO_ASC);
        $objHipoteseLegalDTO->retNumIdHipoteseLegal();
        $objHipoteseLegalDTO->retStrNome();

        return InfraArray::converterArrInfraDTO(
            $this->listar($objHipoteseLegalDTO),
            'Nome',
            'IdHipoteseLegal'
        );
    }

    public function retArrHipoteseLegalRemota()
    {

        $objPenHipoteseLegalDTO = new PenHipoteseLegalDTO();
        $objPenHipoteseLegalDTO->setDistinct(true);
        $objPenHipoteseLegalDTO->setOrdStrNome(InfraDTO::$TIPO_ORDENACAO_ASC);
        $objPenHipoteseLegalDTO->retNumIdHipoteseLegal();
        $objPenHipoteseLegalDTO->retStrNome();

        return InfraArray::converterArrInfraDTO(
            $this->listar($objPenHipoteseLegalDTO),
            'Nome',
            'IdHipoteseLegal'
        );
    }


    public function retRelacaoHipoteseLegal($data)
    {

        $objPenRelHipoteseLegalDTO = new PenRelHipoteseLegalDTO();
        $objPenRelHipoteseLegalDTO->setStrTipo('E');
        $objPenRelHipoteseLegalDTO->retTodos();

        if (isset($data['filtroBarramento'])) {
            $objPenRelHipoteseLegalDTO->setNumIdBarramento($data['filtroBarramento']);
        }

        if (isset($data['filtroLocal'])) {
            $objPenRelHipoteseLegalDTO->setNumIdHipoteseLegal($data['filtroLocal']);
        }

        $response["arrRelacaoHipoteses"]=$this->listar($objPenRelHipoteseLegalDTO);
        $response["dtoRelacaoHipoteses"]=$objPenRelHipoteseLegalDTO;

        return $response;
    }



    public function deleteRelHipoteseLegal($dblIdMap)
    {

        $objPenRelHipoteseLegalDTO = new PenRelHipoteseLegalDTO();
            
            $objPenRelHipoteseLegalDTO->setDblIdMap($dblIdMap);
            $this->excluir($objPenRelHipoteseLegalDTO);
        
        
 
    }






}
