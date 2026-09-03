unit Dian.ProveedorTecnologico;

{$mode delphi}

interface

uses
  Dian.Models,
  Dian.Sender; // reutiliza TDianResponse (ya es generico: RespuestaCruda, no XmlRespuesta)

type
  { Unico contrato que el negocio/POS conoce. No sabe ni le importa si por
    debajo hay XML+firma+SOAP (DIAN directa) o JSON+HTTP sin firma
    (Proveedor Emision, The Factory, DisPapeles...). Cada proveedor es una
    implementacion de esta interfaz - ver Dian.EmisorService (DIAN directa)
    y Dian.ProveedorEmision (ejemplo del otro extremo). }
  IDianProveedorTecnologico = interface
    ['{A1E1F1B0-0006-4A11-9B11-000000000006}']
    function Emitir(Data: TDianDocumentoBase): TDianResponse;
    function ConsultarEstado(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

implementation

end.
