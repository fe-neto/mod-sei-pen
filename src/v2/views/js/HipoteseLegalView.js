

function inicializar() {
  infraEfeitoTabelas();
}


function onClickBtnPesquisar() {
   
    // let data = document.querySelector("#urlPagina");
    // document.getElementById('frmAcompanharEstadoProcesso').action = data.getAttribute("data-js");
    document.getElementById('frmAcompanharEstadoProcesso').submit();
}

function tratarEnter(ev) {
  var key = infraGetCodigoTecla(ev);
  if (key == 13) {
    onClickBtnPesquisar();
  }
  return true;
}




function onClickBtnNovo(){
    
    let data = document.querySelector("#urlCadastro").getAttribute("data-urlSei");
     window.location = data;
}


function onClickBtnExcluir(){

  try {

      var len = jQuery('input[name*=chkInfraItem]:checked').length;

      if(len > 0){

          if(confirm('Confirma a exclusão de ' + len + ' mapeamento(s) ?')) {
              var form = jQuery('#frmAcompanharEstadoProcesso');
              form.append("<input type='hidden' name='acaoPen' value='excluir' />")
              form.submit();
          }
      }
      else {

          alert('Selecione pelo menos um mapeamento para Excluir');
      }
  }
  catch(e){

      alert('Erro : ' + e.message);
  }
}



function onCLickLinkDelete(tag,idDeletado) {

  
  var row = jQuery(tag).parents('tr:first');

  var strEspecieDocumental = row.find('td:eq(1)').text();
  var strTipoDocumento = row.find('td:eq(2)').text();

  if (confirm('Confirma a exclusão do mapeamento "' + strEspecieDocumental + ' x ' + strTipoDocumento + '"?')) {

    var form = jQuery('#frmAcompanharEstadoProcesso');
    form.append("<input type='hidden' name='acaoPen' value='excluir' />")
    form.submit();

   }

}