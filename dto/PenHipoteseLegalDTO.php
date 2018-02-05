<?php

require_once dirname(__FILE__).'/../../../SEI.php';
/**
 * DTO de cadastro do Hipotese Legal no Barramento
 *
 * @author Join Tecnologia
 */

class PenHipoteseLegalDTO extends InfraDTO {

    public function getStrNomeTabela() {
        return 'md_pen_hipotese_legal';
    }
    
    public function montar() {
        $this->adicionarAtributoTabela(InfraDTO::$PREFIXO_NUM, 'IdHipoteseLegal', 'id_hipotese_legal');
        $this->adicionarAtributoTabela(InfraDTO::$PREFIXO_STR, 'Nome', 'nome');
        $this->adicionarAtributoTabela(InfraDTO::$PREFIXO_NUM, 'Identificacao', 'identificacao');  
        $this->adicionarAtributoTabela(InfraDTO::$PREFIXO_STR, 'Ativo', 'sin_ativo');  
        
        $this->configurarPK('IdHipoteseLegal',InfraDTO::$TIPO_PK_SEQUENCIAL);

        //$this->configurarExclusaoLogica('Ativo', 'N');
    }
}
