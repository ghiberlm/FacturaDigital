program Project5;

{$IFDEF FPC}
  {$MODE Delphi}
{$ENDIF}

{$APPTYPE CONSOLE}

uses
  SysUtils,
  mormot.core.os,
  mormot.core.base,
  Dian.LibXml2;

const
  // XML de juguete: namespace declarado en la raiz, para ver que el C14N
  // inclusivo lo arrastra a los hijos y el exclusivo no
  XML_PRUEBA: RawUtf8 =
    '<raiz xmlns:a="urn:ejemplo:a" xmlns:b="urn:noUsado:b"><a:hijo>contenido</a:hijo></raiz>';

  // Prueba 2: Ordenamiento de atributos y normalización de comillas
  XML_ATRIBUTOS: RawUtf8 = 
    '<root z="ultimo" b="segundo" a="primero" xmlns:ns="urn:test" ns:y="val" ns:x="val"/>';

  // Prueba 3: Espacios en blanco y saltos de línea (Line Endings Normalization)
  XML_WHITESPACE: RawUtf8 = 
    '<texto attr="linea1' + #13#10 + 'linea2">' + #13#10 + '  Contenido con   espacios   ' + #13#10 + '</texto>';

  // Prueba 4: Elementos vacíos vs con contenido
  XML_ELEMENTOS_VACIOS: RawUtf8 = 
    '<padre><vacio></vacio><otro/></padre>';

  // Prueba 5: Heredación de namespaces complejos (Inclusivo vs Exclusivo)
  XML_NAMESPACES_ANIDADOS: RawUtf8 = 
    '<env:Envelope xmlns:env="http://schemas.xmlsoap.org/soap/envelope/" xmlns:ds="http://www.w3.org/2000/09/xmldsig#">' +
    '  <env:Body>' +
    '    <DianDoc xmlns="urn:dian:gov:co:facturaelectronica">' +
    '      <Detalle>Valor</Detalle>' +
    '    </DianDoc>' +
    '  </env:Body>' +
    '</env:Envelope>';

  // Prueba 6: Simulación de un fragmento real de Factura Electrónica DIAN (UBL 2.1)
  XML_FACTURA_DIAN: RawUtf8 = 
    '<Invoice xmlns="urn:oasis:names:specification:ubl:schema:xsd:Invoice-2" ' +
    'xmlns:ext="urn:oasis:names:specification:ubl:schema:xsd:CommonExtensionComponents-2" ' +
    'xmlns:cac="urn:oasis:names:specification:ubl:schema:xsd:CommonAggregateComponents-2" ' +
    'xmlns:cbc="urn:oasis:names:specification:ubl:schema:xsd:CommonBasicComponents-2">' +
    '  <ext:UBLExtensions>' +
    '    <ext:UBLExtension>' +
    '      <ext:ExtensionContent/>' +
    '    </ext:UBLExtension>' +
    '  </ext:UBLExtensions>' +
    '  <cbc:ID>SEFE12345</cbc:ID>' +
    '  <cac:AccountingSupplierParty>' +
    '    <cac:Party>' +
    '      <cac:PartyIdentification>' +
    '        <cbc:ID schemeID="31">900123456</cbc:ID>' +
    '      </cac:PartyIdentification>' +
    '    </cac:Party>' +
    '  </cac:AccountingSupplierParty>' +
    '</Invoice>';

  // XSD de juguete: exige que <persona> tenga <nombre> como único hijo
  XSD_PRUEBA_CONTENIDO: RawUtf8 =
    '<?xml version="1.0"?>' +
    '<xs:schema xmlns:xs="http://www.w3.org/2001/XMLSchema">' +
    '  <xs:element name="persona">' +
    '    <xs:complexType>' +
    '      <xs:sequence>' +
    '        <xs:element name="nombre" type="xs:string"/>' +
    '      </xs:sequence>' +
    '    </xs:complexType>' +
    '  </xs:element>' +
    '</xs:schema>';

  XML_VALIDO: RawUtf8 = '<persona><nombre>Ana</nombre></persona>';
  XML_INVALIDO: RawUtf8 = '<persona><apellido>Perez</apellido></persona>';


procedure ProbarC14N;
var
  Inclusivo, Exclusivo: RawUtf8;
begin
  WriteLn('=== 1. Canonicalizacion (C14N) Base ===');
  WriteLn('XML original:');
  WriteLn('  ', XML_PRUEBA);
  try
    Inclusivo := DianC14N(XML_PRUEBA, false);
    WriteLn('C14N inclusivo:');
    WriteLn('  ', Inclusivo);

    Exclusivo := DianC14N(XML_PRUEBA, True);
    WriteLn('C14N exclusivo:');
    WriteLn('  ', Exclusivo);
  except
    on E: Exception do
      WriteLn('ERROR en C14N: ', E.Message);
  end;
  WriteLn;
end;

procedure EjecutarPruebasC14NAdicionales;
type
  TPruebaItem = record
    Nombre: string;
    Xml: RawUtf8;
  end;
var
  Pruebas: array[1..4] of TPruebaItem;
  i: Integer;
begin
  // Inicializamos los valores en tiempo de ejecución para Delphi 2007
  Pruebas[1].Nombre := 'Orden de atributos y comillas';
  Pruebas[1].Xml := XML_ATRIBUTOS;

  Pruebas[2].Nombre := 'Normalizacion de Whitespace y CR/LF';
  Pruebas[2].Xml := XML_WHITESPACE;

  Pruebas[3].Nombre := 'Tags vacios vs auto-cerrados';
  Pruebas[3].Xml := XML_ELEMENTOS_VACIOS;

  Pruebas[4].Nombre := 'Namespaces SOAP / WS-Security (Inclusivo vs Exclusivo)';
  Pruebas[4].Xml := XML_NAMESPACES_ANIDADOS;

  WriteLn('=== PRUEBAS AVANZADAS DE CANONICALIZACION (C14N) ===');
  for i := Low(Pruebas) to High(Pruebas) do
  begin
    WriteLn(Format('--- [%d] %s ---', [i, Pruebas[i].Nombre]));
    WriteLn('Original  : ', Pruebas[i].Xml);
    try
      WriteLn('Inclusivo : ', DianC14N(Pruebas[i].Xml, False));
      WriteLn('Exclusivo : ', DianC14N(Pruebas[i].Xml, True));
    except
      on E: Exception do
        WriteLn('ERROR: ', E.Message);
    end;
    WriteLn;
  end;
end;

procedure ProbarCasoRealDian;
var
  RespuestaInclusiva, RespuestaExclusiva: RawUtf8;
begin
  WriteLn('=== PRUEBA DE FUEGO: Estructura UBL 2.1 (DIAN) ===');
  WriteLn('XML original:');
  WriteLn('  ', XML_FACTURA_DIAN);
  try
    RespuestaInclusiva := DianC14N(XML_FACTURA_DIAN, False);
    WriteLn('C14N Inclusivo (OK para XAdES - Dian.Signer):');
    WriteLn('  ', RespuestaInclusiva);
    WriteLn;

    RespuestaExclusiva := DianC14N(XML_FACTURA_DIAN, True);
    WriteLn('C14N Exclusivo (OK para WS-Security - Dian.Sender):');
    WriteLn('  ', RespuestaExclusiva);
  except
    on E: Exception do
      WriteLn('ERROR CRITICO PROCESANDO XML DIAN: ', E.Message);
  end;
  WriteLn;
end;


procedure ProbarValidacionXsd;
var
  XsdPath: string;
  Errores: TRawUtf8DynArray;
  Valido: Boolean;
  i: Integer;
begin
  WriteLn('=== PRUEBAS DE VALIDACION XSD ===');

  // 1. Creamos un archivo XSD temporal en disco para que libxml2 pueda leerlo
  XsdPath := IncludeTrailingPathDelimiter(ExtractFilePath(ParamStr(0))) + 'esquema_prueba.xsd';
  try
    // Usamos mORMot para guardar el string a archivo de forma limpia
    FileFromString(XSD_PRUEBA_CONTENIDO,XsdPath);

    // --- PRUEBA A: XML Válido ---
    WriteLn('Probando XML VALIDO: ', XML_VALIDO);
    Valido := DianValidateXsd(XML_VALIDO, XsdPath, Errores);
    if Valido then
      WriteLn('  Resultado: ¡EXITO! El XML cumple con el XSD.')
    else
    begin
      WriteLn('  Resultado: FALLO (No esperado). Errores:');
      for i := 0 to High(Errores) do
        WriteLn('    - ', string(Errores[i]));
    end;
    WriteLn;

    // --- PRUEBA B: XML Inválido ---
    WriteLn('Probando XML INVALIDO: ', XML_INVALIDO);
    Valido := DianValidateXsd(XML_INVALIDO, XsdPath, Errores);
    if Valido then
      WriteLn('  Resultado: VALIDO (No esperado, debio fallar).')
    else
    begin
      WriteLn('  Resultado: ¡CORRECTO! El XSD rechazo el XML.');
      WriteLn('  Mensajes de error capturados de libxml2:');
      for i := 0 to High(Errores) do
        WriteLn('    -> ', string(Errores[i]));
    end;

  finally
    // Limpiamos el archivo temporal
    if FileExists(XsdPath) then
      DeleteFile(XsdPath);
  end;
  WriteLn;
end;

begin
  try
    ProbarC14N;
    EjecutarPruebasC14NAdicionales;
    ProbarCasoRealDian;

    ProbarValidacionXsd;
  except
    on E: Exception do
      WriteLn('EXCEPCION NO MANEJADA: ', E.ClassName, ': ', E.Message);
  end;
  WriteLn('Fin. Presiona ENTER para salir...');
  ReadLn;
end.
