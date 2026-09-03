unit Dian.CasosDeUso;

{$mode delphi}

interface

uses
  mormot.core.base,
  Dian.Models,
  Dian.XmlBuilder,
  Dian.BuilderRegistry,
  Dian.EndpointRegistry,
  Dian.Signer,
  Dian.Sender,
  Dian.EmisorService,
  Dian.ProveedorEmision,
  Dian.ProveedorTecnologico;

procedure CasoDeUso_DianDirecta;
procedure CasoDeUso_ProveedorEmision;

implementation

{ ---- datos de la factura: IDENTICOS en los dos casos de uso ---- }

function ArmarFacturaDeEjemplo: TDianFacturaData;
var
  Item: TDianItem;
begin
  result := TDianFacturaData.Create;
  result.Prefijo := 'SETP';
  result.Consecutivo := 1001;
  result.FechaEmision := Now;
  result.Ambiente := amHabilitacion;

  result.Emisor.Nit := '900123456';
  result.Emisor.RazonSocial := 'Mi Empresa SAS';

  result.Adquiriente.Nit := '123456789';
  result.Adquiriente.DV := '6';
  result.Adquiriente.RazonSocial := 'Empresa XYZ';

  Item := result.Items.Add;
  Item.Descripcion := 'TV';
  Item.Codigo := '3';
  Item.Cantidad := 1;
  Item.ValorUnitario := 1400000;
  Item.ValorTotal := 1400000;

  result.Totales.SubTotal := 1700000;
  result.Totales.TotalFactura := 1700000;
end;

{ Recibe el resultado y lo procesa - IDENTICO en los dos casos de uso.
  No sabe ni le importa que proveedor lo genero. }
procedure ProcesarRespuesta(const Etiqueta: RawUtf8; Respuesta: Dian.Sender.TDianResponse);
begin
  try
    case Respuesta.Estado of
      deAceptado:  WriteLn(Etiqueta, ': aceptada, trackId=', Respuesta.TrackId);
      deRechazado: WriteLn(Etiqueta, ': rechazada - ', Respuesta.RespuestaCruda);
      deError:     WriteLn(Etiqueta, ': error de comunicacion/validacion');
      deEnProceso: WriteLn(Etiqueta, ': en proceso, trackId=', Respuesta.TrackId);
    end;
  finally
    Respuesta.Free;
  end;
end;

{ =========================================================
  CASO 1: DIAN DIRECTA - arma XML, firma XAdES, sobre SOAP firmado
  ========================================================= }

procedure CasoDeUso_DianDirecta;
var
  Builders: IDianXmlBuilderRegistry;
  Endpoints: IDianEndpointRegistry;
  Signer: IDianXmlSigner;
  Sender: IDianSender;
  Proveedor: IDianProveedorTecnologico; // <- el tipo con el que trabaja el negocio
  Factura: TDianFacturaData;
  Respuesta: Dian.Sender.TDianResponse;
begin
  // --- composition root: una vez, al arrancar la app ---
  Builders := TDianXmlBuilderRegistry.Create;
  Builders.RegisterBuilder(dkFactura, TFacturaXmlBuilder.Create);
  Builders.RegisterBuilder(dkNotaCredito, TNotaXmlBuilder.Create);
  Builders.RegisterBuilder(dkNotaDebito, TNotaXmlBuilder.Create);
  Builders.RegisterBuilder(dkNotaDocumentoSoporte, TNotaXmlBuilder.Create);
  Builders.RegisterBuilder(dkDocumentoSoporte, TDocumentoSoporteXmlBuilder.Create);

  Endpoints := TDianEndpointRegistry.Create;
  Endpoints.RegisterEndpoint(dkFactura, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkNotaCredito, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkNotaDebito, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkNotaDocumentoSoporte, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkDocumentoSoporte, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');

  Signer := TDianXadesSigner.Create;
  Sender := TDianSoapSender.Create(Endpoints, 'C:\certs\empresa.pfx', 'clave-cert');
  Proveedor := TDianEmisorService.Create(Builders, Signer, Sender, 'C:\certs\empresa.pfx', 'clave-cert');

  // --- uso: solo IDianProveedorTecnologico, nada de XML/SOAP/firma visible aqui ---
  Factura := ArmarFacturaDeEjemplo;
  try
    Respuesta := Proveedor.Emitir(Factura);
    ProcesarRespuesta('DIAN directa', Respuesta);

    // mas adelante, con el trackId que haya devuelto:
    // Respuesta := Proveedor.ConsultarEstado(TrackId, dkFactura);
  finally
    Factura.Free;
  end;
end;

{ =========================================================
  CASO 2: PROVEEDOR EMISION - arma JSON, no firma nada
  ========================================================= }

procedure CasoDeUso_ProveedorEmision;
var
  Builders: IDianJsonBuilderRegistry;
  Sender: IDianJsonSender;
  Proveedor: IDianProveedorTecnologico; // <- MISMO tipo que en el caso 1
  Factura: TDianFacturaData;
  Respuesta: Dian.Sender.TDianResponse;
begin
  // --- composition root: una vez, al arrancar la app ---
  Builders := TDianJsonBuilderRegistry.Create;
  Builders.RegisterBuilder(dkFactura, TFacturaJsonBuilder.Create);
  // TODO: registrar el resto de tipos a medida que armes sus builders JSON

  Sender := TProveedorEmisionSender.Create(
    'https://api.proveedoremision.com/v1/invoices', // TODO: endpoint real
    'tu-api-token');                                  // TODO: token real

  Proveedor := TProveedorEmisionService.Create(Builders, Sender);

  // --- uso: MISMA forma que el caso 1 - ni Factura ni el codigo que
  //     llama a Emitir saben que aqui no hay firma ni SOAP ---
  Factura := ArmarFacturaDeEjemplo;
  try
    Respuesta := Proveedor.Emitir(Factura);
    ProcesarRespuesta('Proveedor Emision', Respuesta);
  finally
    Factura.Free;
  end;
end;

end.
