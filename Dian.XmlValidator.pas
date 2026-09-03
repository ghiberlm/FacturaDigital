unit Dian.XmlValidator;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models;

type
  TDianVerificacionEsquema = class
  public
    EsValida: Boolean;
    Errores: TRawUtf8DynArray; // uno por cada violacion de esquema encontrada
    procedure RaiseIfInvalid;
  end;

  { PENDIENTE: implementacion real esperando los XSD oficiales del Anexo
    Tecnico (uno por familia de documento: Invoice, CreditNote, DebitNote,
    ApplicationResponse, NominaIndividual...). Solo para debug/pruebas -
    igual que IDianXmlSignatureVerifier, se inyecta opcional en
    TDianEmisorService y no corre en produccion salvo que se pida.
    Cuando tengas los XSD: la implementacion tipica en mORMot es cargar el
    XSD y usar el validador de MSXML/libxml2 disponible en tu plataforma -
    eso se define cuando llegue el momento, no antes, para no adivinar
    con que libreria de validacion XSD cuentas. }
  IDianXmlValidator = interface
    ['{A1E1F1B0-000B-4A11-9B11-00000000000B}']
    function Validate(const Xml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionEsquema;
  end;

  { Implementacion "nula" - siempre dice valido. Sirve como valor por
    defecto seguro mientras no tengas los XSD: si la inyectas por error en
    vez de nil, no rompe nada, solo no valida nada tampoco. }
  TDianXmlValidatorPendiente = class(TInterfacedObject, IDianXmlValidator)
  public
    function Validate(const Xml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionEsquema;
  end;

implementation

{ TDianVerificacionEsquema }

procedure TDianVerificacionEsquema.RaiseIfInvalid;
var
  i: Integer;
  Msg: RawUtf8;
begin
  if EsValida then
    Exit;
  Msg := '';
  for i := 0 to High(Errores) do
    Msg := Msg + Errores[i] + '; ';
  raise Exception.CreateFmt('XML invalido contra el esquema: %s', [Msg]);
end;

{ TDianXmlValidatorPendiente }

function TDianXmlValidatorPendiente.Validate(const Xml: RawUtf8; Kind: TDianDocumentKind): TDianVerificacionEsquema;
begin
  result := TDianVerificacionEsquema.Create;
  result.EsValida := True; // no valida nada todavia - ver TODO de la unidad
end;

end.
