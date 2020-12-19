<?php

use PHPUnit\Framework\TestCase;

final class HipoteseLegalRNTest extends TestCase
{

    public function testRetorarHipRemotaComSucesso()
    {
        //cenario

        $objBdMock=$this->createMock(BancoSEI::class);
        $objSessao=$this->createMock(SessaoSEI::class);
        $objHipoteseLegal=new PenHipoteseLegalv2RN($objBdMock,$objSessao);
        $acao="pen_map_hipotese_legal_envio_listar";

        $objSessao->method("validarAuditarPermissao")
        ->willReturn(true);

        $respostaEsperada=[
            'Controle Interno',
            'Documento Preparatorio'
        ];


        $objBdMock->method("retArrHipoteseLegalRemota")
        ->willReturn($respostaEsperada);

        //acao
        $result=$objHipoteseLegal->getHipotesesRemotasConectado($acao);


        //validacao

        $this->assertEquals($respostaEsperada,$result);


    }
}
