unit Dian.Usage.Example;

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
  Dian.EmisorService;

procedure EmitirFacturaDeEjemplo;

implementation

procedure EmitirFacturaDeEjemplo;
var
  Builders: IDianXmlBuilderRegistry;
  Endpoints: IDianEndpointRegistry;
  Signer: IDianXmlSigner;
  Sender: IDianSender;
  Emisor: TDianEmisorService;
  Factura: TDianFacturaData;
  Item: TDianItem;
  Respuesta: TDianResponse;
begin
  // --- composition root: esto se arma UNA sola vez, al arrancar la app ---
  Builders := TDianXmlBuilderRegistry.Create;
  Builders.RegisterBuilder(dkFactura, TFacturaXmlBuilder.Create);
  Builders.RegisterBuilder(dkNotaCredito, TNotaXmlBuilder.Create);
  Builders.RegisterBuilder(dkNotaDebito, TNotaXmlBuilder.Create); // misma estructura UBL
  Builders.RegisterBuilder(dkDocumentoSoporte, TDocumentoSoporteXmlBuilder.Create);

  Endpoints := TDianEndpointRegistry.Create;
  Endpoints.RegisterEndpoint(dkFactura, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkNotaCredito, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkNotaDebito, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  Endpoints.RegisterEndpoint(dkDocumentoSoporte, 'https://vpfe-hab.dian.gov.co/WcfDianCustomerServices.svc');
  // los 4 eventos RADIAN comparten su propio endpoint - se confirma la URL
  // real cuando implementemos ese flujo

  Signer := TDianXadesSigner.Create;
  Sender := TDianSoapSender.Create(Endpoints, 'C:\certs\empresa.pfx', 'clave-cert');
  Emisor := TDianEmisorService.Create(Builders, Signer, Sender, 'C:\certs\empresa.pfx', 'clave-cert');
  try
    // --- esto se crea UNA vez POR CADA factura a emitir ---
    Factura := TDianFacturaData.Create;
    try
      Factura.Prefijo := 'SETP';
      Factura.Consecutivo := 990000001;
      Factura.FechaEmision := Now;
      Factura.Ambiente := amHabilitacion;

      Factura.Emisor.Nit := '900123456';
      Factura.Emisor.RazonSocial := 'Mi Empresa SAS';

      Factura.Adquiriente.Nit := '222222222222';
      Factura.Adquiriente.RazonSocial := 'Consumidor final';

      Item := Factura.Items.Add;
      Item.Descripcion := 'Producto X';
      Item.Cantidad := 1;
      Item.ValorUnitario := 50000;
      Item.PorcentajeIva := 19;
      Item.ValorTotal := 59500;

      Factura.Totales.SubTotal := 50000;
      Factura.Totales.TotalIva := 9500;
      Factura.Totales.TotalFactura := 59500;

      // --- una sola llamada: builder correcto -> firma -> envio ---
      Respuesta := Emisor.Emitir(Factura);
      try
        case Respuesta.Estado of
          deAceptado:  WriteLn('Aceptada, trackId=', Respuesta.TrackId);
          deRechazado: WriteLn('Rechazada: ', Respuesta.XmlRespuesta);
          deError:     WriteLn('Error de comunicacion/validacion');
        end;
      finally
        Respuesta.Free;
      end;
    finally
      Factura.Free;
    end;
  finally
    Emisor.Free;
  end;
end;

end.
