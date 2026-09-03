unit Dian.LibXml2;

{$IFDEF FPC}
  {$mode delphi}
{$ENDIF}

interface

uses
  mormot.core.base;

const
  {$IFDEF MSWINDOWS}
    {$IFDEF CPU64}
      LibXml2Name = 'libxml2_64.dll';
    {$ELSE}
      LibXml2Name = 'libxml2_32.dll';
    {$ENDIF}
  {$ENDIF}
  {$IFDEF LINUX}
    LibXml2Name = 'libxml2.so.2'; // o 'libxml2.so' segun la distro
  {$ENDIF}

type
  // Punteros opacos que usa libxml2 internamente - Pascal no necesita
  // conocer su estructura real, solo pasarlos de una funcion a otra
  TXmlDocPtr = Pointer;
  TXmlSchemaPtr = Pointer;
  TXmlSchemaParserCtxtPtr = Pointer;
  TXmlSchemaValidCtxtPtr = Pointer;

  // Callback ESTRUCTURADO (xmlStructuredErrorFunc) para capturar los
  // mensajes de una validacion XSD. Reemplaza al callback variadic
  // (xmlSchemaValidityErrorFunc, estilo printf) que dejamos antes como
  // "no probado" - confirmado que SI fallaba: el mensaje llegaba crudo
  // sin formatear ("%s" literal en vez del texto real), porque una
  // funcion Pascal de 2 parametros no puede procesar varargs de C.
  // xmlSchemaSetValidStructuredErrors entrega en cambio un puntero a un
  // struct xmlError ya armado, con el mensaje como campo de texto normal.
  PXmlError = ^TXmlError;
  TXmlError = record
    domain: Integer;
    code: Integer;
    msg: PAnsiChar;        // "message" en el struct C - renombrado, palabra reservada
    level: Integer;
    fileName: PAnsiChar;   // "file" en el struct C - renombrado
    line: Integer;
    str1: PAnsiChar;
    str2: PAnsiChar;
    str3: PAnsiChar;
    int1: Integer;
    int2: Integer;
    ctxt: Pointer;
    node: Pointer;
  end;

  TXmlStructuredErrorFunc = procedure(userData: Pointer; error: PXmlError); cdecl;

// ---- documento / memoria ----
function xmlReadMemory(buffer: PAnsiChar; size: Integer;
  URL, encoding: PAnsiChar; options: Integer): TXmlDocPtr; cdecl; external LibXml2Name;
procedure xmlFreeDoc(doc: TXmlDocPtr); cdecl; external LibXml2Name;

type
  TXmlFreeFunc = procedure(mem: Pointer); cdecl;
  PXmlFreeFunc = ^TXmlFreeFunc;

// OJO: xmlFree NO es una funcion exportada (XMLPUBFUN) - es una VARIABLE
// exportada (XMLPUBVAR) que contiene un puntero a la funcion real de
// liberacion (ver xmlmemory.h: "XMLPUBVAR xmlFreeFunc xmlFree;"). Delphi
// 2007 no acepta declarar variables "external" en absoluto (solo rutinas),
// asi que este simbolo se resuelve en tiempo de ejecucion con
// LoadLibrary/GetProcAddress, unicamente para este caso.
//
// OJO 2 (el bug real): GetProcAddress(hLib, 'xmlFree') NO devuelve el
// puntero a la funcion de liberacion - devuelve la DIRECCION DE LA
// VARIABLE "xmlFree" dentro de la DLL (un void**, no un void*). Hay que
// desreferenciar UNA VEZ MAS para llegar a la funcion real. Por eso
// xmlFreeVarAddr es PXmlFreeFunc (puntero a puntero-a-funcion), y se
// llama como "xmlFreeVarAddr^(mem)", no "TXmlFreeFunc(xmlFreeVarAddr)(mem)".
var
  xmlFreeVarAddr: PXmlFreeFunc;

// ---- canonicalizacion (c14n.h) ----
// mode: 0 = XML_C14N_1_0 (inclusivo - Dian.Signer/XAdES)
//       1 = XML_C14N_EXCLUSIVE_1_0 (exclusivo - Dian.Sender/WS-Security)
function xmlC14NDocDumpMemory(doc: TXmlDocPtr; nodes: Pointer; mode: Integer;
  inclusive_ns_prefixes: PPAnsiChar; with_comments: Integer;
  doc_txt_ptr: PPAnsiChar): Integer; cdecl; external LibXml2Name;

// ---- schema XSD (xmlschemas.h) ----
function xmlSchemaNewParserCtxt(URL: PAnsiChar): TXmlSchemaParserCtxtPtr; cdecl; external LibXml2Name;
function xmlSchemaParse(ctxt: TXmlSchemaParserCtxtPtr): TXmlSchemaPtr; cdecl; external LibXml2Name;
procedure xmlSchemaFreeParserCtxt(ctxt: TXmlSchemaParserCtxtPtr); cdecl; external LibXml2Name;
function xmlSchemaNewValidCtxt(schema: TXmlSchemaPtr): TXmlSchemaValidCtxtPtr; cdecl; external LibXml2Name;
procedure xmlSchemaSetValidStructuredErrors(ctxt: TXmlSchemaValidCtxtPtr;
  serror: TXmlStructuredErrorFunc; userCtx: Pointer); cdecl; external LibXml2Name;
function xmlSchemaValidateDoc(ctxt: TXmlSchemaValidCtxtPtr; doc: TXmlDocPtr): Integer; cdecl; external LibXml2Name;
procedure xmlSchemaFreeValidCtxt(ctxt: TXmlSchemaValidCtxtPtr); cdecl; external LibXml2Name;
procedure xmlSchemaFree(schema: TXmlSchemaPtr); cdecl; external LibXml2Name;

// ---- funciones propias en Pascal, para usar desde el resto del proyecto ----

{ Canonicaliza un XML completo. Exclusive=False -> C14N inclusivo (el que
  usa Dian.Signer para XAdES); Exclusive=True -> exc-c14n (el que usa
  Dian.Sender para WS-Security). Reemplaza NodoXml.C14N de OXml. }
function DianC14N(const Xml: RawUtf8; Exclusive: Boolean = False): RawUtf8;

{ Valida un XML (en memoria, sin escribirlo a disco) contra un XSD en
  disco. True si es valido; si no, Errors trae los mensajes acumulados. }
function DianValidateXsd(const Xml: RawUtf8; const XsdPath: string;
  out Errors: TRawUtf8DynArray): Boolean;

implementation

uses
  SysUtils,
  Math
  {$IFDEF MSWINDOWS}, Windows{$ENDIF}
  {$IFDEF LINUX}, dynlibs{$ENDIF};

procedure ResolverXmlFree;
{$IFDEF MSWINDOWS}
var
  hLib: HMODULE;
begin
  hLib := GetModuleHandle(PChar(LibXml2Name));
  if hLib = 0 then
    hLib := Windows.LoadLibrary(PChar(LibXml2Name));
  if hLib = 0 then
    raise Exception.CreateFmt('No se pudo cargar %s', [LibXml2Name]);
  xmlFreeVarAddr := PXmlFreeFunc(GetProcAddress(hLib, 'xmlFree'));
  if xmlFreeVarAddr = nil then
    raise Exception.CreateFmt('No se encontro el simbolo xmlFree en %s', [LibXml2Name]);
end;
{$ENDIF}
{$IFDEF LINUX}
var
  hLib: TLibHandle;
begin
  hLib := dynlibs.LoadLibrary(LibXml2Name);
  if hLib = NilHandle then
    raise Exception.CreateFmt('No se pudo cargar %s', [LibXml2Name]);
  xmlFreeVarAddr := PXmlFreeFunc(dynlibs.GetProcedureAddress(hLib, 'xmlFree'));
  if xmlFreeVarAddr = nil then
    raise Exception.CreateFmt('No se encontro el simbolo xmlFree en %s', [LibXml2Name]);
end;
{$ENDIF}

function DianC14N(const Xml: RawUtf8; Exclusive: Boolean): RawUtf8;
var
  Doc: TXmlDocPtr;
  OutText: PAnsiChar;
  OutLen: Integer;
  Mode: Integer;
begin
  Doc := xmlReadMemory(pointer(Xml), Length(Xml), nil, nil, 0);
  if Doc = nil then
    raise Exception.Create('libxml2 no pudo parsear el XML a canonicalizar');
  try
    if Exclusive then
      Mode := 1
    else
      Mode := 0;
    OutText := nil;
    OutLen := xmlC14NDocDumpMemory(Doc, nil, Mode, nil, 0, @OutText);
    if (OutLen < 0) or (OutText = nil) then
      raise Exception.Create('xmlC14NDocDumpMemory fallo al canonicalizar');
    try
      FastSetString(result, OutText, OutLen);
    finally
      xmlFreeVarAddr^(OutText);
    end;
  finally
    xmlFreeDoc(Doc);
  end;
end;

type
  PValidationErrors = ^TRawUtf8DynArray;

procedure OnSchemaStructuredError(userData: Pointer; error: PXmlError); cdecl;
var
  ErrorsPtr: PValidationErrors;
  Mensaje: RawUtf8;
begin
  if userData = nil then
    Exit; // sin contexto no hay donde acumular - se pierde el mensaje, no se cuelga
  ErrorsPtr := PValidationErrors(userData);
  if (error <> nil) and (error^.msg <> nil) then
    Mensaje := RawUtf8(error^.msg)
  else
    Mensaje := '(error de validacion XSD sin mensaje de texto)';
  SetLength(ErrorsPtr^, Length(ErrorsPtr^) + 1);
  ErrorsPtr^[High(ErrorsPtr^)] := Mensaje;
end;

function DianValidateXsd(const Xml: RawUtf8; const XsdPath: string;
  out Errors: TRawUtf8DynArray): Boolean;
var
  Doc: TXmlDocPtr;
  ParserCtxt: TXmlSchemaParserCtxtPtr;
  Schema: TXmlSchemaPtr;
  ValidCtxt: TXmlSchemaValidCtxtPtr;
  ResultCode: Integer;
  ValidationErrors: TRawUtf8DynArray; // local, no global - segura para uso concurrente
begin
  Doc := xmlReadMemory(pointer(Xml), Length(Xml), nil, nil, 0);
  if Doc = nil then
    raise Exception.Create('libxml2 no pudo parsear el XML a validar');
  try
    // RawUtf8, no AnsiString: evita depender del codepage activo del
    // sistema para rutas con acentos/caracteres especiales - libxml2
    // espera UTF-8, no ANSI
    ParserCtxt := xmlSchemaNewParserCtxt(PAnsiChar(RawUtf8(XsdPath)));
    if ParserCtxt = nil then
      raise Exception.CreateFmt('No se pudo abrir el XSD: %s', [XsdPath]);
    try
      Schema := xmlSchemaParse(ParserCtxt);
      if Schema = nil then
        raise Exception.CreateFmt('libxml2 no pudo parsear el XSD (invalido?): %s', [XsdPath]);
      try
        ValidCtxt := xmlSchemaNewValidCtxt(Schema);
        if ValidCtxt = nil then
          raise Exception.Create('No se pudo crear el contexto de validacion XSD');
        try
          // @ValidationErrors (local) via userCtx - el callback escribe
          // ahi, no en una variable de unidad compartida entre llamadas
          xmlSchemaSetValidStructuredErrors(ValidCtxt, OnSchemaStructuredError, @ValidationErrors);
          ResultCode := xmlSchemaValidateDoc(ValidCtxt, Doc);
        finally
          xmlSchemaFreeValidCtxt(ValidCtxt);
        end;
      finally
        xmlSchemaFree(Schema);
      end;
    finally
      xmlSchemaFreeParserCtxt(ParserCtxt);
    end;
  finally
    xmlFreeDoc(Doc);
  end;

  result := ResultCode = 0;
  Errors := ValidationErrors;
end;

initialization
  // FPC/Linux corre con las excepciones de la FPU SIN enmascarar por
  // defecto (Delphi/Windows si las trae enmascaradas - por eso esto no
  // se veia en las pruebas de Windows). Las llamadas a libxml2 (C) pueden
  // dejar flags de la FPU pendientes que FPC interpreta como una
  // excepcion real ("Invalid floating point operation") en la siguiente
  // operacion sensible a la FPU, aunque no haya pasado nada realmente
  // mal. Se enmascaran aqui, una sola vez, para que esta unidad quede
  // lista sin que cada programa que la use tenga que acordarse de hacerlo.
  SetExceptionMask([exInvalidOp, exDenormalized, exZeroDivide, exOverflow, exUnderflow, exPrecision]);
  ResolverXmlFree;

end.
