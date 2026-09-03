unit Dian.Models;

{$mode delphi}

interface

uses
  mormot.core.base;

type
  { Familias de documento, segun la forma de datos que usan (ver el veredicto
    en la conversacion: no todos comparten Emisor/Adquiriente/Items/Totales).
      transaccional -> TDianDocumentoData  (factura, notas, doc. soporte)
      nomina        -> TDianNominaDataBase (nomina, nomina ajuste)
      evento        -> TDianEventoData     (eventos RADIAN)
    dkNotaDocumentoSoporte es un tipo aparte de dkNotaCredito/dkNotaDebito
    (document_type_code 95, distinto de 91/92), aunque comparta la forma
    "nota que referencia un documento anterior".
    dkNomina/dkNominaAjuste usan CUNE y su propio slot de firma (indice 0) -
    ver Dian.Signer.SlotDeFirmaPara.
    dkAcuseRecibo/dkRecibidoBien/dkAceptacionExpresa/dkAceptacionTacita son
    eventos RADIAN (ApplicationResponse), van contra un endpoint distinto -
    ver Dian.EndpointRegistry. }
  TDianDocumentKind = (
    // familia transaccional
    dkFactura, dkNotaCredito, dkNotaDebito, dkDocumentoSoporte, dkNotaDocumentoSoporte,
    // familia nomina
    dkNomina, dkNominaAjuste,
    // familia eventos RADIAN
    dkAcuseRecibo, dkRecibidoBien, dkAceptacionExpresa, dkAceptacionTacita);
  TDianAmbiente = (amHabilitacion, amProduccion);

  { ---- raiz minima: lo unico que comparten las TRES familias ---- }

  TDianDocumentoBase = class
  public
    Kind: TDianDocumentKind;
    Ambiente: TDianAmbiente;
    Prefijo: RawUtf8;
    Consecutivo: Int64;
    FechaEmision: TDateTime;
    // se completa con el identificador (CUFE/CUDE/CUDS/CUNE/CUDEEVENT) una
    // vez calculado
    Identificador: RawUtf8;
    constructor Create(AKind: TDianDocumentKind); virtual;
  end;

  { =========================================================
    FAMILIA TRANSACCIONAL: factura, notas, documento soporte
    ========================================================= }

  TDianParte = class
  public
    Nit: RawUtf8;
    DV: RawUtf8;
    RazonSocial: RawUtf8;
    TipoIdentificacion: RawUtf8; // identification_type_code
    TipoOrganizacion: RawUtf8;   // organization_type_code
    RegimenTipo: RawUtf8;        // regime_type_code
    TaxCode: RawUtf8;            // tax_code
    ResponsabilidadTributaria: RawUtf8; // liability_type_code (R-99-PN...)
    Direccion: RawUtf8;
    Municipio: RawUtf8;
    Pais: RawUtf8;
    Telefono: RawUtf8;
    Email: RawUtf8;
    RegistroMercantil: RawUtf8;
    Idioma: RawUtf8;
    // resto de campos fiscales se agregan a medida que se necesiten -
    // no bloquean el resto del diseno
  end;

  { forma de pago: tu JSON trae una LISTA (payment_forms), no un campo suelto -
    corrige lo que teniamos antes (MedioPago: RawUtf8 en el documento) }
  TDianFormaPago = class
  public
    FormaPagoCodigo: RawUtf8;  // payment_form_code
    MetodoPagoCodigo: RawUtf8; // payment_method_code
  end;

  TDianItem = class
  public
    Codigo: RawUtf8;
    Descripcion: RawUtf8;
    Cantidad: Double;
    UnidadMedidaCodigo: RawUtf8;        // unit_measure_code
    TipoIdentificacionItem: RawUtf8;    // item_identification_type_code
    CantidadBase: Double;               // base_quantity
    GratuitoIndicador: Boolean;         // free_of_charge_indicator
    ValorUnitario: Currency;
    PorcentajeIva: Double;
    ValorTotal: Currency;
    // documento soporte trae ademas tax_totals e invoice_period POR LINEA -
    // se agregan cuando implementemos ese builder en detalle
  end;

  TDianItems = class(TInterfacedObject)
  private
    fList: array of TDianItem;
    function GetItem(Index: Integer): TDianItem;
  public
    destructor Destroy; override;
    function Add: TDianItem;
    function Count: Integer;
    property Items[Index: Integer]: TDianItem read GetItem; default;
  end;

  TDianTotales = class
  public
    SubTotal: Currency;            // line_extension_amount
    BaseGravable: Currency;        // tax_exclusive_amount
    TotalIva: Currency;
    TotalDescuentos: Currency;     // allowance_total_amount
    TotalCargos: Currency;         // charge_total_amount
    TotalFactura: Currency;        // payable_amount (tax_inclusive_amount)
  end;

  TDianDocumentoData = class(TDianDocumentoBase)
  public
    TipoOperacionCodigo: RawUtf8;  // operation_type_code
    TipoDocumentoCodigo: RawUtf8;  // document_type_code
    ResolucionNumero: RawUtf8;     // resolution_number
    Emisor: TDianParte;
    Adquiriente: TDianParte;       // en doc. soporte, es realmente el VENDEDOR - ojo al llenar
    FormasPago: array of TDianFormaPago;
    Items: TDianItems;
    Totales: TDianTotales;
    constructor Create(AKind: TDianDocumentKind); override;
    destructor Destroy; override;
  end;

  TDianFacturaData = class(TDianDocumentoData)
  public
    constructor Create; reintroduce;
  end;

  { notas de credito/debito (factura) y nota de documento soporte:
    las tres referencian el documento que corrigen - mismo shape }
  TDianNotaData = class(TDianDocumentoData)
  public
    FacturaRefNumero: RawUtf8;         // billing_reference.number
    FacturaRefIdentificador: RawUtf8;  // billing_reference.uuid
    FacturaRefFecha: TDateTime;        // billing_reference.issue_date
    MotivoCorreccionCodigo: RawUtf8;   // discrepancy_response.correction_concept_code
    constructor Create(AKind: TDianDocumentKind); reintroduce;
  end;

  TDianDocumentoSoporteData = class(TDianDocumentoData)
  public
    constructor Create; reintroduce;
  end;

  { =========================================================
    FAMILIA NOMINA: nomina individual y su nota de ajuste
    ========================================================= }

  TDianNominaPeriodo = class
  public
    FechaIngreso: TDateTime;
    FechaLiquidacionInicio: TDateTime;
    FechaLiquidacionFin: TDateTime;
    TiempoLaborado: Integer;
  end;

  TDianNominaTrabajador = class
  public
    TipoTrabajador: RawUtf8;
    TipoDocumento: RawUtf8;
    NumeroDocumento: RawUtf8;
    PrimerApellido: RawUtf8;
    SegundoApellido: RawUtf8;
    PrimerNombre: RawUtf8;
    OtrosNombres: RawUtf8;
    SueldoIntegral: Boolean;
    TipoContrato: RawUtf8;
    Sueldo: Currency;
    // salario, alto_riesgo, direccion_trabajo... se agregan segun se necesiten
  end;

  TDianNominaPago = class
  public
    FormaCodigo: RawUtf8;
    MetodoCodigo: RawUtf8;
    Banco: RawUtf8;
    TipoCuenta: RawUtf8;
    NumeroCuenta: RawUtf8;
  end;

  { devengados/deducciones tienen MUCHOS conceptos posibles en el anexo real -
    dejamos solo el total aqui como modelo minimo; el detalle linea por linea
    se agrega cuando implementemos el builder de nomina en serio }
  TDianNominaDataBase = class(TDianDocumentoBase)
  public
    Periodo: TDianNominaPeriodo;
    Trabajador: TDianNominaTrabajador;
    Pago: TDianNominaPago;
    DevengadosTotal: Currency;
    DeduccionesTotal: Currency;
    ComprobanteTotal: Currency;
    constructor Create(AKind: TDianDocumentKind); override;
    destructor Destroy; override;
  end;

  TDianNominaData = class(TDianNominaDataBase)
  public
    constructor Create; reintroduce;
  end;

  { referencia a la nomina que se esta corrigiendo }
  TDianNominaAjusteData = class(TDianNominaDataBase)
  public
    TipoNota: Integer;
    PredecesorPrefijo: RawUtf8;
    PredecesorConsecutivo: RawUtf8;
    PredecesorFechaGen: TDateTime;
    constructor Create; reintroduce;
  end;

  { =========================================================
    FAMILIA EVENTOS RADIAN: acuse de recibo, recibido del bien,
    aceptacion expresa/tacita - todos son ApplicationResponse
    ========================================================= }

  TDianEventoParte = class
  public
    TipoIdentificacion: RawUtf8;
    NumeroIdentificacion: RawUtf8;
    RazonSocial: RawUtf8;
  end;

  TDianEventoPersona = class
  public
    TipoIdentificacion: RawUtf8;
    NumeroIdentificacion: RawUtf8;
    Nombre: RawUtf8;
    Apellido: RawUtf8;
    Cargo: RawUtf8;
  end;

  TDianEventoData = class(TDianDocumentoBase)
  public
    Emisor: TDianEventoParte;     // sender_party
    Receptor: TDianEventoParte;   // receiver_party
    DocumentoRefNumero: RawUtf8;      // document_reference.number
    DocumentoRefIdentificador: RawUtf8; // document_reference.uuid (CUFE de la factura)
    QuienRegistra: TDianEventoPersona; // person - quien hace el acuse/aceptacion
    constructor Create(AKind: TDianDocumentKind); override;
    destructor Destroy; override;
  end;

implementation

{ TDianDocumentoBase }

constructor TDianDocumentoBase.Create(AKind: TDianDocumentKind);
begin
  inherited Create;
  Kind := AKind;
end;

{ TDianItems }

destructor TDianItems.Destroy;
var
  i: Integer;
begin
  for i := 0 to High(fList) do
    fList[i].Free;
  inherited;
end;

function TDianItems.Add: TDianItem;
begin
  result := TDianItem.Create;
  SetLength(fList, Length(fList) + 1);
  fList[High(fList)] := result;
end;

function TDianItems.GetItem(Index: Integer): TDianItem;
begin
  result := fList[Index];
end;

function TDianItems.Count: Integer;
begin
  result := Length(fList);
end;

{ TDianDocumentoData }

constructor TDianDocumentoData.Create(AKind: TDianDocumentKind);
begin
  inherited Create(AKind);
  Emisor := TDianParte.Create;
  Adquiriente := TDianParte.Create;
  Items := TDianItems.Create;
  Totales := TDianTotales.Create;
end;

destructor TDianDocumentoData.Destroy;
var
  i: Integer;
begin
  Emisor.Free;
  Adquiriente.Free;
  Items.Free;
  Totales.Free;
  for i := 0 to High(FormasPago) do
    FormasPago[i].Free;
  inherited;
end;

{ TDianFacturaData }

constructor TDianFacturaData.Create;
begin
  inherited Create(dkFactura);
end;

{ TDianNotaData }

constructor TDianNotaData.Create(AKind: TDianDocumentKind);
begin
  inherited Create(AKind);
end;

{ TDianDocumentoSoporteData }

constructor TDianDocumentoSoporteData.Create;
begin
  inherited Create(dkDocumentoSoporte);
end;

{ TDianNominaDataBase }

constructor TDianNominaDataBase.Create(AKind: TDianDocumentKind);
begin
  inherited Create(AKind);
  Periodo := TDianNominaPeriodo.Create;
  Trabajador := TDianNominaTrabajador.Create;
  Pago := TDianNominaPago.Create;
end;

destructor TDianNominaDataBase.Destroy;
begin
  Periodo.Free;
  Trabajador.Free;
  Pago.Free;
  inherited;
end;

{ TDianNominaData }

constructor TDianNominaData.Create;
begin
  inherited Create(dkNomina);
end;

{ TDianNominaAjusteData }

constructor TDianNominaAjusteData.Create;
begin
  inherited Create(dkNominaAjuste);
end;

{ TDianEventoData }

constructor TDianEventoData.Create(AKind: TDianDocumentKind);
begin
  inherited Create(AKind);
  Emisor := TDianEventoParte.Create;
  Receptor := TDianEventoParte.Create;
  QuienRegistra := TDianEventoPersona.Create;
end;

destructor TDianEventoData.Destroy;
begin
  Emisor.Free;
  Receptor.Free;
  QuienRegistra.Free;
  inherited;
end;

end.
