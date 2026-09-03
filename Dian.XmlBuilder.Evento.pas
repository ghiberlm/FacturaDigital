unit Dian.XmlBuilder.Evento;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.XmlBuilder, // reutiliza IDianXmlBuilder
  OXmlPDOM;

type
  { Mismo contrato IDianXmlBuilder, casteando a TDianEventoData. Los 4
    eventos RADIAN son todos ApplicationResponse - lo unico que cambia
    entre ellos es el codigo de respuesta/concepto que se escribe adentro,
    no la forma general del documento. }
  TDianEventoXmlBuilderBase = class(TInterfacedObject, IDianXmlBuilder)
  protected
    function CalcularIdentificador(Data: TDianEventoData): RawUtf8; virtual; abstract; // CUDEEVENT
    function CodigoRespuesta(Data: TDianEventoData): RawUtf8; virtual; abstract; // el codigo propio de cada evento

    procedure EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData); virtual;
    procedure EscribirEncabezado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData); virtual;
    procedure EscribirParte(Doc: IXMLDocument; Parent: PXMLNode; const Tag: RawUtf8; Parte: TDianEventoParte); virtual;
    procedure EscribirDocumentoReferenciado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData); virtual;
    procedure EscribirPersonaQueRegistra(Doc: IXMLDocument; Root: PXMLNode; Persona: TDianEventoPersona); virtual;
    procedure EscribirRespuesta(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData); virtual;
  public
    { Secuencia fija: identificador -> ApplicationResponse (nodo raiz fijo,
      no cambia por evento) -> extensiones (hueco de firma, slot 1 - igual
      que la familia transaccional) -> encabezado -> emisor/receptor ->
      documento referenciado (el CUFE que se esta respondiendo) -> persona
      que registra -> respuesta especifica del evento. }
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  TAcuseReciboXmlBuilder = class(TDianEventoXmlBuilderBase)
  protected
    function CalcularIdentificador(Data: TDianEventoData): RawUtf8; override;
    function CodigoRespuesta(Data: TDianEventoData): RawUtf8; override;
  end;

  TRecibidoBienXmlBuilder = class(TDianEventoXmlBuilderBase)
  protected
    function CalcularIdentificador(Data: TDianEventoData): RawUtf8; override;
    function CodigoRespuesta(Data: TDianEventoData): RawUtf8; override;
  end;

  { sirve para aceptacion expresa y tacita - mismo shape, distinto codigo }
  TAceptacionXmlBuilder = class(TDianEventoXmlBuilderBase)
  protected
    function CalcularIdentificador(Data: TDianEventoData): RawUtf8; override;
    function CodigoRespuesta(Data: TDianEventoData): RawUtf8; override;
  end;

implementation

{ TDianEventoXmlBuilderBase }

function TDianEventoXmlBuilderBase.Build(Data: TDianDocumentoBase): RawUtf8;
var
  DataE: TDianEventoData;
  Doc: IXMLDocument;
  Root: PXMLNode;
begin
  DataE := Data as TDianEventoData;
  DataE.Identificador := CalcularIdentificador(DataE); // CUDEEVENT
  Doc := CreateXMLDoc('ApplicationResponse');
  Root := Doc.DocumentElement;
  EscribirExtensiones(Doc, Root, DataE);
  EscribirEncabezado(Doc, Root, DataE);
  EscribirParte(Doc, Root, 'cac:SenderParty', DataE.Emisor);
  EscribirParte(Doc, Root, 'cac:ReceiverParty', DataE.Receptor);
  EscribirDocumentoReferenciado(Doc, Root, DataE);
  EscribirPersonaQueRegistra(Doc, Root, DataE.QuienRegistra);
  EscribirRespuesta(Doc, Root, DataE); // usa CodigoRespuesta(DataE)
  result := Doc.Xml;
end;

procedure TDianEventoXmlBuilderBase.EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData);
begin
  // TODO: confirmar si el ApplicationResponse de RADIAN usa el mismo
  // esquema de 3 ext:UBLExtension que la factura (slot de firma = indice 1,
  // igual que la familia transaccional - ya lo asume SlotDeFirmaPara)
end;

procedure TDianEventoXmlBuilderBase.EscribirEncabezado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData);
begin
  // cbc:UBLVersionID, cbc:ID, cbc:UUID (Data.Identificador), cbc:IssueDate/Time
end;

procedure TDianEventoXmlBuilderBase.EscribirParte(Doc: IXMLDocument; Parent: PXMLNode; const Tag: RawUtf8; Parte: TDianEventoParte);
begin
  // TipoIdentificacion, NumeroIdentificacion, RazonSocial
end;

procedure TDianEventoXmlBuilderBase.EscribirDocumentoReferenciado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData);
begin
  // cac:DocumentResponse/cac:DocumentReference con
  // Data.DocumentoRefNumero / Data.DocumentoRefIdentificador (el CUFE)
end;

procedure TDianEventoXmlBuilderBase.EscribirPersonaQueRegistra(Doc: IXMLDocument; Root: PXMLNode; Persona: TDianEventoPersona);
begin
  // TipoIdentificacion, NumeroIdentificacion, Nombre, Apellido, Cargo
end;

procedure TDianEventoXmlBuilderBase.EscribirRespuesta(Doc: IXMLDocument; Root: PXMLNode; Data: TDianEventoData);
begin
  // cac:Response/cbc:ResponseCode := CodigoRespuesta(Data) - el codigo
  // exacto y donde va dentro del esquema se confirma contra el anexo RADIAN
end;

{ TAcuseReciboXmlBuilder }

function TAcuseReciboXmlBuilder.CalcularIdentificador(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // CUDEEVENT
end;

function TAcuseReciboXmlBuilder.CodigoRespuesta(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // codigo de "Acuse de Recibo" segun anexo RADIAN
end;

{ TRecibidoBienXmlBuilder }

function TRecibidoBienXmlBuilder.CalcularIdentificador(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // CUDEEVENT
end;

function TRecibidoBienXmlBuilder.CodigoRespuesta(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // codigo de "Recibo del Bien/Servicio" segun anexo RADIAN
end;

{ TAceptacionXmlBuilder }

function TAceptacionXmlBuilder.CalcularIdentificador(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // CUDEEVENT
end;

function TAceptacionXmlBuilder.CodigoRespuesta(Data: TDianEventoData): RawUtf8;
begin
  result := ''; // codigo de aceptacion expresa o tacita segun Data.Kind
  // (dkAceptacionExpresa vs dkAceptacionTacita)
end;

end.
