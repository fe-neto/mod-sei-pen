<?php

use BeSimple\SoapClient\SoapClient;
use PHPUnit\Framework\TestCase;

final class ProcessoEletronicoRNTest extends TestCase
{
    private $objProcessoEletronicoRN;

    public function setUp() :void
    {
        $this->objProcessoEletronicoRN = new ProcessoEletronicoRN();
    }

    /**
     * Testes do método privado reduzirCampoTexto
     * @dataProvider valueProviderReduzirTexto
     *
     * @return void
     */
    public function testReduzirCampoTexto($input,$esperado,$numTamanhoMaximo = 53)
    {
        //cenario
        $strTexto = $input;
        $strResultadoEsperado = $esperado;

        //acao
        $strResultadoAtual = $this->objProcessoEletronicoRN->reduzirCampoTexto($strTexto, $numTamanhoMaximo);

        //validacao
        $this->assertEquals($strResultadoEsperado, $strResultadoAtual);
        $this->assertTrue(strlen($strResultadoAtual) <= $numTamanhoMaximo);


    }


    public function testConsultarRepositoriosDeEstruturasComSucesso(){


        //cenario
        $objPenWs=$this->createMock(PenWs::class);


       $idDesejado=1;
        $response = [
            "repositoriosEncontrados" =>
                ["repositorio" =>
                    [
                            "id" => 1,
                            "nome" => "testeNome",
                            "ativo" => true
                    ]
                ]
            ];

        $response = json_decode(json_encode($response));

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willReturn($response);
       $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);



        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN->consultarRepositoriosDeEstruturas($idDesejado);


        //validacao
        $this->assertEquals(1, $resultRepoDTO->getNumId());
        $this->assertEquals("testeNome", $resultRepoDTO->getStrNome());
        $this->assertEquals(true, $resultRepoDTO->getBolAtivo());

    }

    public function testDeveRetornarNullCasoNaoHajaRepositorio(){

        //caso de rever e lancar uma exception

        //cenario
        $idDesejado=null;
        $objPenWs=$this->createMock(PenWs::class);

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willReturn(null);
        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN->consultarRepositoriosDeEstruturas($idDesejado);

        //validacao
        $this->assertEquals(null, $resultRepoDTO);


    }

    public function testDeveRetornarErroCasoNaoConecteAoWSaoBuscar1(){

        //cenario
        $objPenWs=$this->createMock(PenWs::class);

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willThrowException(new SoapFault($message="erro de conexao",$code=404));

        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        try{
            $resultRepoDTO=$this->objProcessoEletronicoRN->consultarRepositoriosDeEstruturas($idDesejado);

        }catch(Exception $e){

        }

        //validacao
        $this->assertEquals("Falha na obtenção dos Repositórios de Estruturas Organizacionais", $e->getMessage());
        $this->assertTrue($e instanceof InfraException);


    }



    public function testListarRepositoriosDeEstruturasComSucesso(){


        //cenario
        $objPenWs=$this->createMock(PenWs::class);

        $response = [
            "repositoriosEncontrados" =>
                ["repositorio" =>
                    [
                        [
                                "id" => 1,
                                "nome" => "testeNome",
                                "ativo" => true
                        ],
                        [
                                "id" => 2,
                                "nome" => "testeNome2",
                                "ativo" => true
                        ],
                    ]
                ]
            ];

        $response = json_decode(json_encode($response));

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willReturn($response);
        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);


        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN->listarRepositoriosDeEstruturas();

        //validacao
         $this->assertEquals(2, sizeof($resultRepoDTO));

         $this->assertEquals(1, $resultRepoDTO[0]->getNumId());
         $this->assertEquals("testeNome", $resultRepoDTO[0]->getStrNome());
         $this->assertEquals(true, $resultRepoDTO[0]->getBolAtivo());

         $this->assertEquals(2, $resultRepoDTO[1]->getNumId());
         $this->assertEquals("testeNome2", $resultRepoDTO[1]->getStrNome());
         $this->assertEquals(true, $resultRepoDTO[1]->getBolAtivo());


    }



    public function testDeveRetornarNullCasoNaoHajaListaRepositorio(){


        //cenario
        $objPenWs=$this->createMock(PenWs::class);

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willReturn(null);
        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN->listarRepositoriosDeEstruturas();

        //validacao
        $this->assertEquals(array(), $resultRepoDTO);


    }

    public function testDeveRetornarErroCasoNaoConecteAoWSaoListar(){

        //cenario
        $objPenWs=$this->createMock(PenWs::class);

        $objPenWs->method("consultarRepositoriosDeEstruturas")
        ->willThrowException(new SoapFault($message="erro de conexao",$code=404));

        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        try{
            $resultRepoDTO=$this->objProcessoEletronicoRN->listarRepositoriosDeEstruturas();

        }catch(Exception $e){

        }

        //validacao
        $this->assertEquals("Falha na obtenção dos Repositórios de Estruturas Organizacionais", $e->getMessage());
        $this->assertTrue($e instanceof InfraException);


    }




    public function testConsultarEstruturasSemHierarquiaComSucesso(){


        //cenario
        $objPenWs=$this->createMock(PenWs::class);
        $idRepositorioEstrutura=1;
       $numeroDeIdentificacaoDaEstrutura=1;
       $bolRetornoRaw=false;

        $response = [
            "estruturasEncontradas" =>
                ["estrutura" =>
                    [
                            "numeroDeIdentificacaoDaEstrutura" => 1,
                            "nome" => "testeNome",
                            "sigla" => "ME",
                            "ativo" => true,
                            "aptoParaReceberTramites" => true,
                            "codigoNoOrgaoEntidade" => 200000

                    ],
                "totalDeRegistros"=>1
                ],

            ];

        $response = json_decode(json_encode($response));

        $objPenWs->method("consultarEstruturas")
        ->willReturn($response);
        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);


        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN
            ->consultarEstrutura($idRepositorioEstrutura,$numeroDeIdentificacaoDaEstrutura);


        //validacao
        $this->assertEquals(1, $resultRepoDTO->getNumNumeroDeIdentificacaoDaEstrutura());
        $this->assertEquals("testeNome", $resultRepoDTO->getStrNome());
        $this->assertEquals("ME", $resultRepoDTO->getStrSigla());
        $this->assertEquals(true, $resultRepoDTO->getBolAtivo());
        $this->assertEquals(true, $resultRepoDTO->getBolAptoParaReceberTramites());
        $this->assertEquals(200000, $resultRepoDTO->getStrCodigoNoOrgaoEntidade());

    }


    public function testConsultarEstruturasComHierarquiaComSucesso(){

        //cenario
        $objPenWs=$this->createMock(PenWs::class);
        $idRepositorioEstrutura=1;
        $numeroDeIdentificacaoDaEstrutura=1;
        $bolRetornoRaw=true;

        $response = [
            "estruturasEncontradas" =>
                ["estrutura" =>
                    [
                            "nome" => "testeNome",
                            "sigla" => "ME",
                            "hierarquia" => [ "nivel"=> ["nome" => "Superior" ] ]
                    ],
                "totalDeRegistros"=>1
                ],

            ];

        $response = json_decode(json_encode($response));

        $objPenWs->method("consultarEstruturas")
        ->willReturn($response);
        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);


        //acao
        $resultRepoDTO=$this->objProcessoEletronicoRN
            ->consultarEstrutura($idRepositorioEstrutura,$numeroDeIdentificacaoDaEstrutura,$bolRetornoRaw);

        //validacao
        $this->assertEquals("testeNome", $resultRepoDTO->nome);
        $this->assertEquals("ME", $resultRepoDTO->sigla);
        $this->assertEquals("Superior", $resultRepoDTO->hierarquia->nivel[0]->nome);


    }



    public function testDeveRetornarErroCasoNaoConecteAoWSaoBuscarEstruturas(){

        //cenario
        $objPenWs=$this->createMock(PenWs::class);

        $objPenWs->method("consultarEstruturas")
        ->willThrowException(new SoapFault($message="erro de conexao",$code=404));

        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        try{
            $resultRepoDTO=$this->objProcessoEletronicoRN
                ->consultarEstrutura($idRepositorioEstrutura,$numeroDeIdentificacaoDaEstrutura,$bolRetornoRaw);

        }catch(Exception $e){

        }

        //validacao
        $this->assertEquals("Falha na obtenção de unidades externas", $e->getMessage());
        $this->assertTrue($e instanceof InfraException);


    }

    public function testDeveRetornarErroComParamNulosBuscarEstruturas(){

        //cenario
        $objPenWs=$this->createMock(PenWs::class);
        $idRepositorioEstrutura=null;
        $numeroDeIdentificacaoDaEstrutura=null;
        $bolRetornoRaw=null;

        $objPenWs->method("consultarEstruturas")
        ->willThrowException(new SoapFault($message="erro de conexao",$code=404));

        $this->objProcessoEletronicoRN->setObjPenWsTest($objPenWs);

        //acao
        try{
            $resultRepoDTO=$this->objProcessoEletronicoRN
                ->consultarEstrutura($idRepositorioEstrutura,$numeroDeIdentificacaoDaEstrutura,$bolRetornoRaw);

        }catch(Exception $e){

        }

        //validacao
        $this->assertEquals("Falha na obtenção de unidades externas", $e->getMessage());
        $this->assertTrue($e instanceof InfraException);



    }


    /**
     *
     * @dataProvider valueProviderOperacaoDTO
     *
     */
    public function testConverterOperacaoDTOtiposDocumento($codigo,$tipoDocumento){


        //cenario
        $objOperacaoPEN=new stdClass();
        $objOperacaoPEN->codigo=$codigo;
        $objOperacaoPEN->complemento="quadra 222";
        $objOperacaoPEN->dataHora="2020-01-01";
        $objOperacaoPEN->pessoa=new stdClass();
        $objOperacaoPEN->pessoa->numeroDeIdentificacao="05";
        $objOperacaoPEN->pessoa->nome="Maria";

        //acao
        $resultDTO=$this->objProcessoEletronicoRN->converterOperacaoDTO($objOperacaoPEN);

        //validacao
        $this->assertEquals($tipoDocumento, $resultDTO->getStrNome());

    }



    public function testConverterOperacaoDTOcomParametroNulo(){


        //cenario
        $objOperacaoPEN=null;

        //acao
        try{
            $resultDTO=$this->objProcessoEletronicoRN->converterOperacaoDTO($objOperacaoPEN);
        }catch(Exception $e){ }

        //validacao
        $this->assertEquals('Parâmetro $objOperacaoPEN não informado.', $e->getMessage());
        $this->assertTrue($e instanceof InfraException);
    }

    public function testConverterOperacaoDTOcomCodigoInexistente(){

        //cenario
        $objOperacaoPEN=new stdClass();
        $objOperacaoPEN->codigo="555";
        $objOperacaoPEN->complemento="quadra 222";
        $objOperacaoPEN->dataHora="2020-01-01";
        $objOperacaoPEN->pessoa=new stdClass();
        $objOperacaoPEN->pessoa->numeroDeIdentificacao="05";
        $objOperacaoPEN->pessoa->nome="Maria";

        //acao
        $resultDTO=$this->objProcessoEletronicoRN->converterOperacaoDTO($objOperacaoPEN);


        //validacao
        $this->assertEquals("Registro", $resultDTO->getStrNome());

    }

    public function testConverterOperacaoDTOcomSucesso(){


        //cenario
        $objOperacaoPEN=new stdClass();
        $objOperacaoPEN->codigo="1";
        $objOperacaoPEN->complemento="quadra 222";
        $objOperacaoPEN->dataHora="2020-01-01";
        $objOperacaoPEN->pessoa=new stdClass();
        $objOperacaoPEN->pessoa->numeroDeIdentificacao="05";
        $objOperacaoPEN->pessoa->nome="Maria";

        $dataEsperada=$this->objProcessoEletronicoRN
            ->converterDataSEI($objOperacaoPEN->dataHora);


        //acao
        $resultDTO=$this->objProcessoEletronicoRN->converterOperacaoDTO($objOperacaoPEN);

        //validacao
        $this->assertEquals("quadra 222", $resultDTO->getStrComplemento());
        $this->assertEquals($dataEsperada, $resultDTO->getDthOperacao());
        $this->assertEquals("05", $resultDTO->getStrIdentificacaoPessoaOrigem());
        $this->assertEquals("Maria", $resultDTO->getStrNomePessoaOrigem());




    }




    public function valueProviderReduzirTexto(){

        return [
            "palavraPequenaFinalTexto"=>[
                'input'=> "aaaaaaaaa bbbbbbbbb ccccccccc ddddddddd eeeeeeeee fffffffff ggggggggg hhhhhhhhh iiiiiiiii"
                ,'esperado' => "aaaaaaaaa bbbbbbbbb ccccccccc ddddddddd eeeeeeeee ..."
            ],
            "textoLongoApenasUmaPalavra"=>[
                'input'=> "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                ,'esperado' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ..."
            ],
            "textoLongoUmaPalavraGrandeAoFinal"=>[
                'input'=> "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa aaaaaaaaaaaaaaaaaaaaaa"
                ,'esperado' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ..."
            ],
            "textoLongoVariasPalavraCurtasAoFinal"=>[
                'input'=> "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa aaaaaaaa aaaaaaaaaaaaaaa aaaaaaaaaaaaaaaaaaaaaa"
                ,'esperado' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ..."
            ],
            "textoCurtoAbaixoDoLimite"=>[
                'input'=> "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                ,'esperado' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
            ],
            "textoLongoComUmCaractereForaDoLimite"=>[
                'input'=> "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa"
                ,'esperado' => "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa ..."
            ],
            "textoLongoCortadoComUmCaractereForaDoLimite"=>[
                'input'=>      "aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa a"
                ,'esperado' => "aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa aaaaaaaaa ..."
                ,'numTamanhoMaximo' => 150
            ],
            "textoConsideradoTextoNulo"=>[
                'input'=> null
                ,'esperado' => null
            ],


        ];
    }


    public function valueProviderOperacaoDTO(){

        return [
            "tipoDocumentoRegistro" => ["01" ,"Registro"],
            "tipoDocumentoDocAvulso" => ["02", "Envio de documento avulso/processo" ],
            "tipoDocumentoCancelamento" => ["03", "Cancelamento/exclusão ou envio de documento"],
            "tipoDocumentoRecebimento" => ["04", "Recebimento de documento"],
            "tipoDocumentoAutuacao" => ["05", "Autuação"],
            "tipoDocumentoJuntadaAnexo" => ["06", "Juntada por anexação"],
            "tipoDocumentoJuntadaApensacao" => ["07", "Juntada por apensação"],
            "tipoDocumentoDesapensacao" => ["08", "Desapensação"],
            "tipoDocumentoArquivamento" => ["09", "Arquivamento"],
            "tipoDocumentoArquivamento" => ["10","Arquivamento no Arquivo Nacional"],
            "tipoDocumentoEliminacao" => ["11", "Eliminação"],
            "tipoDocumentoSinistro" => ["12", "Sinistro"],
            "tipoDocumentoReconstituicao" => ["13", "Reconstituição de processo"],
            "tipoDocumentoDesarquivamento" => ["14", "Desarquivamento"],
            "tipoDocumentoDesmembramento" => ["15", "Desmembramento"],
            "tipoDocumentoDesentranhamento" => ["16", "Desentranhamento"],
            "tipoDocumentoEncerramento" => ["17", "Encerramento/abertura de volume no processo"],
            "tipoDocumentoRegistroDeExtravio" => ["18", "Registro de extravio"]
        ];
    }


}



class PenWs extends SoapClient{

    public function consultarRepositoriosDeEstruturas(){return 555;}
    public function consultarEstruturas(){return 555;}



}
