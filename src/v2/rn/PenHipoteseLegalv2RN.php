<?php


require_once DIR_SEI_WEB.'/SEI.php';

/**
 * Description of PenHipoteseLegalRN
 *
 * @author pen
 */
class PenHipoteseLegalv2RN extends InfraRN
{

    private $objBanco;


    //logica para mock de Teste Unitario
    protected function inicializarObjInfraIBanco(){
        if($objBanco==null){
            $this->objBanco= BancoSEI::getInstance();
            return $this->objBanco;
        }
    }

    public function __construct($objBanco=null) {
        $this->objBanco = $objBanco;
    }

    protected function getHipotesesRemotasConectado($data=null){

        try {
            $objBD = new PenHipoteseLegalv2BD($this->inicializarObjInfraIBanco());
            return $objBD->retArrHipoteseLegalRemota();
        }
        catch (Exception $e) {
            throw new InfraException('Erro ao listar parâmetro.', $e);
        }

    }
    protected function getHipotesesLocaisConectado($data=null){

        try {
            $objBD = new PenHipoteseLegalv2BD($this->inicializarObjInfraIBanco());
            return $objBD->retArrHipoteseLegalLocal($data);
        }
        catch (Exception $e) {
            throw new InfraException('Erro ao listar parâmetro.', $e);
        }


    }
    protected function getMapeamentoHipotesesConectado($data){

        try {
            $objBD = new PenHipoteseLegalv2BD($this->inicializarObjInfraIBanco());
            return $objBD->retRelacaoHipoteseLegal($data);
        }
        catch (Exception $e) {
            throw new InfraException('Erro ao listar parâmetro.', $e);
        }


    }

    protected function deleteHipotesesConectado($data){

        try {

            $objBD = new PenHipoteseLegalv2BD($this->inicializarObjInfraIBanco());

            foreach ($data["hipotesesDelete"] as $dblIdMap) {
                $objBD->deleteRelHipoteseLegal($dblIdMap);
            }
            
            return true;

        }
        catch (Exception $e) {
            throw new InfraException('Erro ao excluir parâmetro.', $e);
        }


    }






}
