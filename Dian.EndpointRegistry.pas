unit Dian.EndpointRegistry;

{$mode delphi}

interface

uses
  SysUtils,
  mormot.core.base,
  Dian.Models;

type
  { El sender solo conoce esta interfaz - no sabe si hay uno o varios
    servicios SOAP detras. Mismo patron que IDianXmlBuilderRegistry: quien
    arma la app decide que endpoint corresponde a cada tipo de documento. }
  IDianEndpointRegistry = interface
    ['{A1E1F1B0-0005-4A11-9B11-000000000005}']
    procedure RegisterEndpoint(Kind: TDianDocumentKind; const Url: RawUtf8);
    function EndpointFor(Kind: TDianDocumentKind): RawUtf8;
  end;

  TDianEndpointRegistry = class(TInterfacedObject, IDianEndpointRegistry)
  private
    fEndpoints: array[TDianDocumentKind] of RawUtf8;
  public
    procedure RegisterEndpoint(Kind: TDianDocumentKind; const Url: RawUtf8);
    function EndpointFor(Kind: TDianDocumentKind): RawUtf8;
  end;

implementation

procedure TDianEndpointRegistry.RegisterEndpoint(Kind: TDianDocumentKind; const Url: RawUtf8);
begin
  fEndpoints[Kind] := Url;
end;

function TDianEndpointRegistry.EndpointFor(Kind: TDianDocumentKind): RawUtf8;
begin
  result := fEndpoints[Kind];
  if result = '' then
    raise Exception.CreateFmt('No hay endpoint registrado para %d', [Ord(Kind)]);
end;

end.
