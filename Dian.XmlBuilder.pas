unit Dian.XmlBuilder;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  OXmlPDOM; // DOM de OXml - cambia el uses si usas otro modulo de OXml

type
  { Contrato unico para las TRES familias de documento (transaccional,
    nomina, eventos RADIAN): recibe los datos, entrega el XML UBL SIN
    firmar, con el identificador ya calculado e insertado. Cada familia
    castea internamente a su propio tipo de datos - ver el cast al inicio
    de TDianXmlBuilderBase.Build. }
  IDianXmlBuilder = interface
    ['{A1E1F1B0-0001-4A11-9B11-000000000001}']
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  { Template Method PARA LA FAMILIA TRANSACCIONAL (factura, notas, documento
    soporte) - Build() vive UNA sola vez aqui, con el orden fijo que exige
    el esquema DIAN. Las clases concretas solo aportan los "huecos" que
    realmente cambian por tipo de documento. No sobreescriben Build.
    Nomina y eventos RADIAN NO heredan de esta clase - tienen su propia
    forma de datos (sin Emisor/Adquiriente/Items/Totales) y necesitan su
    propio Template Method: TDianNominaXmlBuilderBase / TDianEventoXmlBuilderBase
    (pendientes - mismo patron, contenido distinto). }
  TDianXmlBuilderBase = class(TInterfacedObject)
  protected
    // -- huecos obligatorios: cada tipo de documento los define --
    function CalcularIdentificador(Data: TDianDocumentoData): RawUtf8; virtual; abstract;
    function NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8; virtual; abstract; // 'Invoice' / 'CreditNote' / 'DebitNote' / 'DocumentoSoporte'

    // -- hueco opcional: por defecto no hace nada; TNotaXmlBuilder lo usa
    //    para agregar cac:BillingReference / cac:DiscrepancyResponse --
    procedure EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData); virtual;

    // -- hooks para el encabezado: lo unico que cambia entre factura/nota/
    //    documento soporte dentro del bloque de encabezado comun.
    //    OJO: DebitNote NO tiene este elemento en absoluto (confirmado
    //    contra plantilla real) - devolver '' para omitirlo. --
    function TagTipoDocumento(Data: TDianDocumentoData): RawUtf8; virtual; abstract;
    function TextoProfileID(Data: TDianDocumentoData): RawUtf8; virtual;

    // -- pasos comunes: mismo comportamiento para los tres tipos --
    procedure EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData); virtual;
    procedure EscribirContenidoExtensionDian(Doc: IXMLDocument; Parent: PXMLNode; Data: TDianDocumentoData); virtual;
    procedure EscribirEncabezado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData); virtual;
    procedure EscribirParte(Doc: IXMLDocument; Parent: PXMLNode; const Tag: RawUtf8; Parte: TDianParte); virtual;
    procedure EscribirItems(Doc: IXMLDocument; Parent: PXMLNode; Items: TDianItems); virtual;
    procedure EscribirTotales(Doc: IXMLDocument; Parent: PXMLNode; Totales: TDianTotales); virtual;
  public
    { Secuencia fija - NO se sobreescribe en las clases hijas.
      Castea Data a TDianDocumentoData (esta familia) y sigue el orden que
      exige el esquema DIAN, byte a byte: extensiones (con el hueco vacio
      para la firma) -> encabezado -> contenido especifico -> partes ->
      items -> totales. }
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  TFacturaXmlBuilder = class(TDianXmlBuilderBase, IDianXmlBuilder)
  protected
    function CalcularIdentificador(Data: TDianDocumentoData): RawUtf8; override; // CUFE
    function NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8; override;
    function TagTipoDocumento(Data: TDianDocumentoData): RawUtf8; override;
  end;

  { sirve para nota credito y nota debito: misma estructura UBL, distinta operacion }
  TNotaXmlBuilder = class(TDianXmlBuilderBase, IDianXmlBuilder)
  protected
    function CalcularIdentificador(Data: TDianDocumentoData): RawUtf8; override; // CUDE
    function NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8; override;
    function TagTipoDocumento(Data: TDianDocumentoData): RawUtf8; override;
    procedure EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData); override;
  end;

  TDocumentoSoporteXmlBuilder = class(TDianXmlBuilderBase, IDianXmlBuilder)
  protected
    function CalcularIdentificador(Data: TDianDocumentoData): RawUtf8; override; // CUDS
    function NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8; override;
    function TagTipoDocumento(Data: TDianDocumentoData): RawUtf8; override;
  end;

implementation

uses
  SysUtils;

{ TDianXmlBuilderBase }

function TDianXmlBuilderBase.Build(Data: TDianDocumentoBase): RawUtf8;
var
  DataT: TDianDocumentoData;
  Doc: IXMLDocument;
  Root: PXMLNode;
begin
  DataT := Data as TDianDocumentoData; // esta clase solo sirve la familia transaccional
  DataT.Identificador := CalcularIdentificador(DataT);        // 1. CUFE/CUDE/CUDS primero: el QR de la extension lo necesita
  Doc := CreateXMLDoc(NombreNodoRaiz(DataT));                 // 2. nodo raiz segun el tipo
  Root := Doc.DocumentElement;
  EscribirExtensiones(Doc, Root, DataT);                      // 3. ext:UBLExtensions: datos DIAN + hueco vacio de firma + hueco reservado
  EscribirEncabezado(Doc, Root, DataT);                       // 4. cbc:ID, cbc:UUID, cbc:IssueDate...
  EscribirContenidoEspecifico(Doc, Root, DataT);               // 5. no-op salvo en notas (referencia a la factura)
  EscribirParte(Doc, Root, 'cac:AccountingSupplierParty', DataT.Emisor);
  if DataT.Kind <> dkDocumentoSoporte then
    EscribirParte(Doc, Root, 'cac:AccountingCustomerParty', DataT.Adquiriente);
  EscribirItems(Doc, Root, DataT.Items);
  EscribirTotales(Doc, Root, DataT.Totales);
  result := Doc.Xml;
end;

procedure TDianXmlBuilderBase.EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData);
begin
  // por defecto no hace nada - factura y documento soporte no lo necesitan
end;

procedure TDianXmlBuilderBase.EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData);
var
  ExtensionesNode, Ext1, Ext2, Ext3: PXMLNode;
begin
  // ext:UBLExtensions con TRES ext:UBLExtension, en este orden exacto:
  //   1) sts:DianExtensions (QR con el Identificador, control de numeracion, software provider)
  //   2) hueco vacio: aqui inserta TDianXadesSigner el ds:Signature completo
  //   3) hueco vacio reservado - se queda vacio siempre
  ExtensionesNode := Root.AppendChild('ext:UBLExtensions');

  Ext1 := ExtensionesNode.AppendChild('ext:UBLExtension').AppendChild('ext:ExtensionContent');
  EscribirContenidoExtensionDian(Doc, Ext1, Data); // usa Data.Identificador para el QR

  Ext2 := ExtensionesNode.AppendChild('ext:UBLExtension').AppendChild('ext:ExtensionContent');
  // Ext2 se deja vacio a proposito

  Ext3 := ExtensionesNode.AppendChild('ext:UBLExtension').AppendChild('ext:ExtensionContent');
  // Ext3 se deja vacio a proposito
end;

procedure TDianXmlBuilderBase.EscribirContenidoExtensionDian(Doc: IXMLDocument; Parent: PXMLNode; Data: TDianDocumentoData);
begin
  // sts:InvoiceControl, sts:InvoiceSource, sts:SoftwareProvider,
  // sts:SoftwareSecurityCode, sts:QRCode (con Data.Identificador embebido)
end;

function TDianXmlBuilderBase.TextoProfileID(Data: TDianDocumentoData): RawUtf8;
begin
  // TODO: confirmar si Nota/Documento Soporte usan el mismo texto o uno
  // propio - la plantilla que revisamos solo mostraba el caso de factura
  result := 'DIAN 2.1: Factura Electrónica de Venta';
end;

procedure TDianXmlBuilderBase.EscribirEncabezado(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData);
var
  AmbienteCodigo: RawUtf8;
begin
  // TODO: confirmar cual numero corresponde a cual ambiente en el anexo
  // vigente - aqui asumo la convencion mas comun (1=produccion,
  // 2=habilitacion/pruebas), sin haberla verificado contra la fuente oficial
  if Data.Ambiente = amProduccion then
    AmbienteCodigo := '1'
  else
    AmbienteCodigo := '2';

  Root.AppendChild(Doc.CreateElement('cbc:UBLVersionID')).Text := 'UBL 2.1';
  Root.AppendChild(Doc.CreateElement('cbc:CustomizationID')).Text := Data.TipoOperacionCodigo;
  Root.AppendChild(Doc.CreateElement('cbc:ProfileID')).Text := TextoProfileID(Data);
  Root.AppendChild(Doc.CreateElement('cbc:ProfileExecutionID')).Text := AmbienteCodigo;
  Root.AppendChild(Doc.CreateElement('cbc:ID')).Text := Data.Prefijo + IntToStr(Data.Consecutivo);

  with Root.AppendChild(Doc.CreateElement('cbc:UUID')) do
  begin
    SetAttribute('schemeID', AmbienteCodigo);
    SetAttribute('schemeName', 'CUFE-SHA384'); // TODO: CUDE/CUDS/CUNE segun Kind - confirmar nombre exacto por tipo
    Text := Data.Identificador;
  end;

  Root.AppendChild(Doc.CreateElement('cbc:IssueDate')).Text := FormatDateTime('yyyy-mm-dd', Data.FechaEmision);
  Root.AppendChild(Doc.CreateElement('cbc:IssueTime')).Text := FormatDateTime('hh:nn:ss', Data.FechaEmision) + '-05:00';
  // DebitNote no tiene este elemento (confirmado) - TagTipoDocumento
  // devuelve '' en ese caso y aqui simplemente se omite
  if TagTipoDocumento(Data) <> '' then
    Root.AppendChild(Doc.CreateElement(TagTipoDocumento(Data))).Text := Data.TipoDocumentoCodigo;

  // TODO: cbc:Note - el modelo actual no tiene un campo de notas de texto libre

  Root.AppendChild(Doc.CreateElement('cbc:DocumentCurrencyCode')).Text := Data.Moneda;
  Root.AppendChild(Doc.CreateElement('cbc:LineCountNumeric')).Text := IntToStr(Data.Items.Count);

  // TODO: bloques opcionales que la plantilla de referencia mostro y que
  // este modelo todavia no cubre: OrderReference, AdditionalDocumentReference,
  // ReceiptDocumentReference, Delivery, PrepaidPayment(s), AllowanceCharges,
  // PaymentExchangeRate, WithHoldingTaxTotals - se agregan a TDianDocumentoData
  // el dia que los necesites, no antes
end;

procedure TDianXmlBuilderBase.EscribirParte(Doc: IXMLDocument; Parent: PXMLNode; const Tag: RawUtf8; Parte: TDianParte);
begin
  // arma cac:AccountingSupplierParty o cac:AccountingCustomerParty
end;

procedure TDianXmlBuilderBase.EscribirItems(Doc: IXMLDocument; Parent: PXMLNode; Items: TDianItems);
begin
  // una cac:InvoiceLine / cac:CreditNoteLine por cada TDianItem
end;

procedure TDianXmlBuilderBase.EscribirTotales(Doc: IXMLDocument; Parent: PXMLNode; Totales: TDianTotales);
begin
  // cac:LegalMonetaryTotal
end;

{ TFacturaXmlBuilder }

function TFacturaXmlBuilder.CalcularIdentificador(Data: TDianDocumentoData): RawUtf8;
begin
  result := ''; // SHA-384 sobre los campos del anexo tecnico para CUFE
end;

function TFacturaXmlBuilder.NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8;
begin
  result := 'Invoice';
end;

function TFacturaXmlBuilder.TagTipoDocumento(Data: TDianDocumentoData): RawUtf8;
begin
  result := 'cbc:InvoiceTypeCode'; // confirmado por la plantilla de referencia
end;

{ TNotaXmlBuilder }

function TNotaXmlBuilder.CalcularIdentificador(Data: TDianDocumentoData): RawUtf8;
begin
  result := ''; // SHA-384 sobre los campos del anexo tecnico para CUDE
end;

function TNotaXmlBuilder.NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8;
begin
  case Data.Kind of
    dkNotaCredito:
      result := 'CreditNote';
    dkNotaDebito:
      result := 'DebitNote';
    dkNotaDocumentoSoporte:
      // CONFIRMADO contra plantilla real (95.blade.php): usa <CreditNote>,
      // no un elemento raiz propio
      result := 'CreditNote';
  else
    result := 'DebitNote';
  end;
end;

function TNotaXmlBuilder.TagTipoDocumento(Data: TDianDocumentoData): RawUtf8;
begin
  case Data.Kind of
    dkNotaCredito, dkNotaDocumentoSoporte:
      result := 'cbc:CreditNoteTypeCode'; // confirmado contra plantilla real (91 y 95)
  else
    result := ''; // DebitNote CONFIRMADO que no tiene este elemento (92.blade.php)
  end;
end;

procedure TNotaXmlBuilder.EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianDocumentoData);
var
  Nota: TDianNotaData;
  DiscrepancyResponse, BillingReference, InvoiceDocumentReference: PXMLNode;
  EsquemaCude: RawUtf8;
begin
  Nota := Data as TDianNotaData;

  // orden CONFIRMADO contra plantilla real (91.blade.php): primero
  // DiscrepancyResponse, despues BillingReference - no al reves

  // ---- cac:DiscrepancyResponse ----
  DiscrepancyResponse := Doc.CreateElement('cac:DiscrepancyResponse');
  Root.AppendChild(DiscrepancyResponse);
  if Nota.FacturaRefNumero <> '' then
    DiscrepancyResponse.AppendChild(Doc.CreateElement('cbc:ReferenceID')).Text := Nota.FacturaRefNumero;
  DiscrepancyResponse.AppendChild(Doc.CreateElement('cbc:ResponseCode')).Text := Nota.MotivoCorreccionCodigo;
  // TODO: cbc:Description - la plantilla la saca de un catalogo propio
  // (nombre del concepto de correccion segun el codigo). Nuestro modelo
  // solo guarda el codigo (MotivoCorreccionCodigo), no el catalogo de
  // nombres - falta esa tabla de referencia del Anexo Tecnico si quieres
  // escribir este texto tambien.

  // ---- cac:BillingReference ----
  BillingReference := Doc.CreateElement('cac:BillingReference');
  Root.AppendChild(BillingReference);
  InvoiceDocumentReference := Doc.CreateElement('cac:InvoiceDocumentReference');
  BillingReference.AppendChild(InvoiceDocumentReference);
  InvoiceDocumentReference.AppendChild(Doc.CreateElement('cbc:ID')).Text := Nota.FacturaRefNumero;

  // TODO: la plantilla usa 'CUDS-SHA384' cuando el documento que se
  // corrige es un documento soporte, y el scheme_name real (normalmente
  // 'CUFE-SHA384') en el resto de casos. Nuestro modelo no distingue que
  // tipo de documento se esta referenciando, asi que aqui asumo el caso
  // mas comun (referenciar una factura) - ajustar si tu nota referencia
  // un documento soporte o a otra nota.
  if Data.Kind = dkNotaDocumentoSoporte then
    EsquemaCude := 'CUDS-SHA384'
  else
    EsquemaCude := 'CUFE-SHA384';
  with InvoiceDocumentReference.AppendChild(Doc.CreateElement('cbc:UUID')) do
  begin
    SetAttribute('schemeName', EsquemaCude);
    Text := Nota.FacturaRefIdentificador;
  end;

  InvoiceDocumentReference.AppendChild(Doc.CreateElement('cbc:IssueDate')).Text :=
    FormatDateTime('yyyy-mm-dd', Nota.FacturaRefFecha);

  // TODO: cbc:DocumentTypeCode dentro de InvoiceDocumentReference - la
  // plantilla solo lo agrega para un tipo de documento especifico
  // (typeDocument->id == '26', no identificado que es exactamente) - no
  // lo escribo hasta confirmar cuando aplica
end;

{ TDocumentoSoporteXmlBuilder }

function TDocumentoSoporteXmlBuilder.CalcularIdentificador(Data: TDianDocumentoData): RawUtf8;
begin
  result := ''; // SHA-384 sobre los campos del anexo tecnico para CUDS
end;

function TDocumentoSoporteXmlBuilder.NombreNodoRaiz(Data: TDianDocumentoData): RawUtf8;
begin
  // CONFIRMADO contra plantilla real (05.blade.php): documento soporte usa
  // <Invoice> igual que la factura - NO existe un elemento raiz separado
  // "DocumentoSoporte". Corregido: antes asumiamos mal esto.
  result := 'Invoice';
end;

function TDocumentoSoporteXmlBuilder.TagTipoDocumento(Data: TDianDocumentoData): RawUtf8;
begin
  result := 'cbc:InvoiceTypeCode'; // confirmado - documento soporte es un Invoice mas
end;

end.
