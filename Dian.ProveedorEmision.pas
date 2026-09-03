unit Dian.ProveedorEmision;

{$mode delphi}

interface

uses
  mormot.core.base,
  mormot.core.variants,
  Dian.Models,
  Dian.Sender, // reutiliza TDianResponse
  Dian.ProveedorTecnologico;

type
  { Espejo de IDianXmlBuilder pero para JSON - sin C14N, sin slots de
    firma, sin ninguno de los conceptos propios de XML/DIAN. }
  IDianJsonBuilder = interface
    ['{A1E1F1B0-0007-4A11-9B11-000000000007}']
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  { Mismo patron que IDianXmlBuilderRegistry (Dian.BuilderRegistry), solo
    que resuelve builders JSON en vez de builders XML. }
  IDianJsonBuilderRegistry = interface
    ['{A1E1F1B0-0008-4A11-9B11-000000000008}']
    procedure RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianJsonBuilder);
    function Resolve(Kind: TDianDocumentKind): IDianJsonBuilder;
  end;

  TDianJsonBuilderRegistry = class(TInterfacedObject, IDianJsonBuilderRegistry)
  private
    fBuilders: array[TDianDocumentKind] of IDianJsonBuilder;
  public
    procedure RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianJsonBuilder);
    function Resolve(Kind: TDianDocumentKind): IDianJsonBuilder;
  end;

  { Factura en JSON, siguiendo la forma que ya compartiste
    (send/number/prefix/customer/invoice_lines/legal_monetary_totals) -
    lo minimo para probar la forma; tu completas los campos que falten. }
  TFacturaJsonBuilder = class(TInterfacedObject, IDianJsonBuilder)
  public
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  { Espejo de IDianSender, pero sin firma - solo POST JSON con el token/
    llave que te de el proveedor (no un certificado, ellos no exigen
    XAdES). Interfaz separada de IDianSender a proposito: no comparten
    forma (uno recibe XML+Kind+Async, el otro JSON+Kind y ya). }
  IDianJsonSender = interface
    ['{A1E1F1B0-0009-4A11-9B11-000000000009}']
    function Send(const Json: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
    function GetStatus(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

  TProveedorEmisionSender = class(TInterfacedObject, IDianJsonSender)
  private
    fEndpoint: RawUtf8;
    fApiToken: RawUtf8; // autenticacion propia del proveedor, no certificado DIAN
  public
    constructor Create(const AEndpoint, AApiToken: RawUtf8);
    function Send(const Json: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
    function GetStatus(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

  { Implementacion de IDianProveedorTecnologico para Proveedor Emision.
    Comparalo con TDianEmisorService: MISMO contrato hacia afuera: no
    compone ningun IDianXmlSigner porque este proveedor no firma nada -
    esa es la diferencia real entre los dos, y queda visible aqui mismo
    con lo que la clase NO tiene, no con un parametro "firmar: boolean". }
  TProveedorEmisionService = class(TInterfacedObject, IDianProveedorTecnologico)
  private
    fBuilders: IDianJsonBuilderRegistry;
    fSender: IDianJsonSender;
  public
    constructor Create(ABuilders: IDianJsonBuilderRegistry; ASender: IDianJsonSender);
    function Emitir(Data: TDianDocumentoBase): TDianResponse;
    function ConsultarEstado(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
  end;

implementation

uses
  mormot.core.json;

{ TDianJsonBuilderRegistry }

procedure TDianJsonBuilderRegistry.RegisterBuilder(Kind: TDianDocumentKind; Builder: IDianJsonBuilder);
begin
  fBuilders[Kind] := Builder;
end;

function TDianJsonBuilderRegistry.Resolve(Kind: TDianDocumentKind): IDianJsonBuilder;
begin
  result := fBuilders[Kind];
  if result = nil then
    raise Exception.CreateFmt('No hay builder JSON registrado para %d', [Ord(Kind)]);
end;

{ TFacturaJsonBuilder }

function TFacturaJsonBuilder.Build(Data: TDianDocumentoBase): RawUtf8;
var
  DataT: TDianDocumentoData;
  Doc, Customer, Totales, Lines: TDocVariantData;
  i: Integer;
begin
  DataT := Data as TDianDocumentoData;

  Doc.InitObject([
    'send', True,
    'number', DataT.Consecutivo,
    'prefix', DataT.Prefijo
    // TODO: operation_type_code, document_type_code, resolution_number,
    // date, time, currency_type_code - ya estan en TDianDocumentoData,
    // solo falta mapearlos aqui
  ], JSON_FAST);

  Customer.InitObject([
    'identification_number', DataT.Adquiriente.Nit,
    'dv', DataT.Adquiriente.DV,
    'name', DataT.Adquiriente.RazonSocial
    // TODO: resto de campos fiscales de TDianParte
  ], JSON_FAST);
  Doc.AddValue('customer', Variant(Customer));

  Totales.InitObject([
    'line_extension_amount', DataT.Totales.SubTotal,
    'tax_inclusive_amount', DataT.Totales.TotalFactura,
    'payable_amount', DataT.Totales.TotalFactura
    // TODO: tax_exclusive_amount, allowance_total_amount, charge_total_amount
  ], JSON_FAST);
  Doc.AddValue('legal_monetary_totals', Variant(Totales));

  Lines.InitArray([], JSON_FAST);
  for i := 0 to DataT.Items.Count - 1 do
    Lines.AddItem(_ObjFast([
      'description', DataT.Items[i].Descripcion,
      'code', DataT.Items[i].Codigo,
      'invoiced_quantity', DataT.Items[i].Cantidad,
      'price_amount', DataT.Items[i].ValorUnitario,
      'line_extension_amount', DataT.Items[i].ValorTotal
      // TODO: unit_measure_code, item_identification_type_code, base_quantity
    ]));
  Doc.AddValue('invoice_lines', Variant(Lines));

  result := Doc.ToJson;
end;

{ TProveedorEmisionSender }

constructor TProveedorEmisionSender.Create(const AEndpoint, AApiToken: RawUtf8);
begin
  inherited Create;
  fEndpoint := AEndpoint;
  fApiToken := AApiToken;
end;

function TProveedorEmisionSender.Send(const Json: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
begin
  // TODO: POST fEndpoint con header de autenticacion (fApiToken) y Json
  // como cuerpo - via mormot.net.client, igual que en TDianSoapSender pero
  // sin SOAP: content-type application/json, sin sobre, sin firma.
  result := TDianResponse.Create;
  result.Proveedor := 'ProveedorEmision';
  // TODO: parsear la respuesta JSON -> Estado/Codigo/Mensaje/Identificador/TrackId
end;

function TProveedorEmisionSender.GetStatus(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
begin
  // TODO: GET/POST de consulta con la Referencia que devolvio Send()
  result := TDianResponse.Create;
  result.Proveedor := 'ProveedorEmision';
  // TODO: parsear la respuesta JSON -> Estado/Codigo/Mensaje/Identificador/TrackId
end;

{ TProveedorEmisionService }

constructor TProveedorEmisionService.Create(ABuilders: IDianJsonBuilderRegistry; ASender: IDianJsonSender);
begin
  inherited Create;
  fBuilders := ABuilders;
  fSender := ASender;
end;

function TProveedorEmisionService.Emitir(Data: TDianDocumentoBase): TDianResponse;
var
  Json: RawUtf8;
begin
  Json := fBuilders.Resolve(Data.Kind).Build(Data);
  result := fSender.Send(Json, Data.Kind); // sin paso de firma - a diferencia de TDianEmisorService.Emitir
end;

function TProveedorEmisionService.ConsultarEstado(const Referencia: RawUtf8; Kind: TDianDocumentKind): TDianResponse;
begin
  result := fSender.GetStatus(Referencia, Kind);
end;

end.
