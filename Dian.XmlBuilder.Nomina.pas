unit Dian.XmlBuilder.Nomina;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.XmlBuilder, // reutiliza IDianXmlBuilder
  OXmlPDOM;

type
  { Mismo contrato IDianXmlBuilder que la familia transaccional, pero esta
    base castea a TDianNominaDataBase - nomina no tiene Emisor/Adquiriente/
    Items/Totales, tiene Periodo/Trabajador/Pago/Devengados/Deducciones.
    El slot de firma tambien es distinto (indice 0, no 1 - ver
    Dian.Signer.SlotDeFirmaPara), pero eso lo resuelve el Signer solo con
    el Kind; aqui no hay que hacer nada especial por eso. }
  TDianNominaXmlBuilderBase = class(TInterfacedObject, IDianXmlBuilder)
  protected
    function CalcularIdentificador(Data: TDianNominaDataBase): RawUtf8; virtual; abstract; // CUNE
    function NombreNodoRaiz(Data: TDianNominaDataBase): RawUtf8; virtual;

    // hueco opcional: TDianNominaAjusteXmlBuilder lo usa para el bloque
    // "Predecesor" (la nomina que esta corrigiendo)
    procedure EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase); virtual;

    procedure EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase); virtual;
    procedure EscribirPeriodo(Doc: IXMLDocument; Root: PXMLNode; Periodo: TDianNominaPeriodo); virtual;
    procedure EscribirTrabajador(Doc: IXMLDocument; Root: PXMLNode; Trabajador: TDianNominaTrabajador); virtual;
    procedure EscribirPago(Doc: IXMLDocument; Root: PXMLNode; Pago: TDianNominaPago); virtual;
    procedure EscribirDevengadosDeducciones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase); virtual;
  public
    { Secuencia fija, igual de espiritu que la familia transaccional:
      identificador -> nodo raiz -> extensiones (hueco de firma) ->
      periodo -> trabajador -> pago -> devengados/deducciones ->
      contenido especifico (solo ajuste). }
    function Build(Data: TDianDocumentoBase): RawUtf8;
  end;

  TDianNominaXmlBuilder = class(TDianNominaXmlBuilderBase)
  protected
    function CalcularIdentificador(Data: TDianNominaDataBase): RawUtf8; override;
  end;

  TDianNominaAjusteXmlBuilder = class(TDianNominaXmlBuilderBase)
  protected
    function CalcularIdentificador(Data: TDianNominaDataBase): RawUtf8; override;
    function NombreNodoRaiz(Data: TDianNominaDataBase): RawUtf8; override;
    procedure EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase); override;
  end;

implementation

{ TDianNominaXmlBuilderBase }

function TDianNominaXmlBuilderBase.Build(Data: TDianDocumentoBase): RawUtf8;
var
  DataN: TDianNominaDataBase;
  Doc: IXMLDocument;
  Root: PXMLNode;
begin
  DataN := Data as TDianNominaDataBase;
  DataN.Identificador := CalcularIdentificador(DataN); // CUNE
  Doc := CreateXMLDoc(NombreNodoRaiz(DataN));
  Root := Doc.DocumentElement;
  EscribirExtensiones(Doc, Root, DataN);              // hueco de firma en slot 0
  EscribirPeriodo(Doc, Root, DataN.Periodo);
  EscribirTrabajador(Doc, Root, DataN.Trabajador);
  EscribirPago(Doc, Root, DataN.Pago);
  EscribirDevengadosDeducciones(Doc, Root, DataN);
  EscribirContenidoEspecifico(Doc, Root, DataN);       // no-op salvo ajuste
  result := Doc.Xml;
end;

function TDianNominaXmlBuilderBase.NombreNodoRaiz(Data: TDianNominaDataBase): RawUtf8;
begin
  result := 'NominaIndividual';
end;

procedure TDianNominaXmlBuilderBase.EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase);
begin
  // por defecto no hace nada - la nomina normal no lo necesita
end;

procedure TDianNominaXmlBuilderBase.EscribirExtensiones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase);
begin
  // TODO: confirmar contra el anexo de nomina si trae el mismo esquema de
  // 3 ext:UBLExtension que la factura, o una forma distinta. El hueco de
  // firma va en INDICE 0 aqui (no 1) - eso ya lo sabe TDianXadesSigner
  // via SlotDeFirmaPara, pero el orden en que ESTE metodo cree los nodos
  // tiene que respetarlo.
end;

procedure TDianNominaXmlBuilderBase.EscribirPeriodo(Doc: IXMLDocument; Root: PXMLNode; Periodo: TDianNominaPeriodo);
begin
  // FechaIngreso, FechaLiquidacionInicio/Fin, TiempoLaborado
end;

procedure TDianNominaXmlBuilderBase.EscribirTrabajador(Doc: IXMLDocument; Root: PXMLNode; Trabajador: TDianNominaTrabajador);
begin
  // TipoTrabajador, TipoDocumento, NumeroDocumento, nombres/apellidos, Sueldo...
end;

procedure TDianNominaXmlBuilderBase.EscribirPago(Doc: IXMLDocument; Root: PXMLNode; Pago: TDianNominaPago);
begin
  // FormaCodigo, MetodoCodigo, Banco, TipoCuenta, NumeroCuenta
end;

procedure TDianNominaXmlBuilderBase.EscribirDevengadosDeducciones(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase);
begin
  // hoy el modelo solo trae los totales (DevengadosTotal/DeduccionesTotal/
  // ComprobanteTotal) - si necesitas el detalle linea por linea (conceptos
  // de devengado/deduccion), primero se amplia TDianNominaDataBase en
  // Dian.Models.pas, despues se completa este metodo
end;

{ TDianNominaXmlBuilder }

function TDianNominaXmlBuilder.CalcularIdentificador(Data: TDianNominaDataBase): RawUtf8;
begin
  result := ''; // CUNE - SHA-384 sobre los campos que exige el anexo de nomina
end;

{ TDianNominaAjusteXmlBuilder }

function TDianNominaAjusteXmlBuilder.CalcularIdentificador(Data: TDianNominaDataBase): RawUtf8;
begin
  result := ''; // CUNE de la nota de ajuste (puede diferir del calculo base - confirmar anexo)
end;

function TDianNominaAjusteXmlBuilder.NombreNodoRaiz(Data: TDianNominaDataBase): RawUtf8;
begin
  result := 'NominaIndividualDeAjuste';
end;

procedure TDianNominaAjusteXmlBuilder.EscribirContenidoEspecifico(Doc: IXMLDocument; Root: PXMLNode; Data: TDianNominaDataBase);
var
  Ajuste: TDianNominaAjusteData;
begin
  Ajuste := Data as TDianNominaAjusteData;
  // bloque "Predecesor": Ajuste.TipoNota, PredecesorPrefijo,
  // PredecesorConsecutivo, PredecesorFechaGen
end;

end.
