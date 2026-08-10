@extends('legal.layout')

@section('title', 'Eliminación de datos')
@section('meta_description', 'Cómo solicitar que Reino Aromas elimine de forma permanente tu información personal y tu historial de conversaciones.')

@section('content')

    <p>
        Si te comunicaste con Reino Aromas por WhatsApp, Instagram o Facebook,
        guardamos tu nombre, tu identificador de contacto y el historial de la
        conversación para poder atenderte. Puedes pedirnos que eliminemos toda esa
        información <strong>en cualquier momento y sin costo</strong>.
    </p>

    <h2>Cómo solicitar la eliminación</h2>

    <p>Elige la vía que te resulte más cómoda:</p>

    <h3>Opción 1 — Por correo electrónico</h3>

    <p>
        Escribe a <a href="mailto:{{ $correoContacto }}?subject=Eliminación%20de%20datos">{{ $correoContacto }}</a>
        indicando:
    </p>

    <ul>
        <li><strong>Asunto:</strong> Eliminación de datos</li>
        <li>
            <strong>En el mensaje:</strong> el número de teléfono o el nombre de
            usuario desde el que nos contactaste, para poder localizar tu
            información.
        </li>
    </ul>

    <h3>Opción 2 — Por el mismo canal de mensajería</h3>

    <p>Envíanos un mensaje por WhatsApp o Instagram con el texto:</p>

    <div class="legal-note">
        <p><strong>Solicito la eliminación de mis datos</strong></p>
    </div>

    <h3>Opción 3 — Desde Facebook</h3>

    <p>
        Si nos contactaste por Facebook, puedes ir a
        <strong>Configuración y privacidad → Configuración → Apps y sitios
        web</strong>, seleccionar Reino Aromas y pulsar <strong>Eliminar</strong>.
        Esto nos notifica automáticamente tu solicitud.
    </p>

    <h2>Qué eliminamos</h2>

    <p>Al procesar tu solicitud borramos de forma permanente:</p>

    <ul>
        <li>Tu nombre y tu foto de perfil.</li>
        <li>Tu número de teléfono o identificador de cuenta.</li>
        <li>El historial completo de mensajes intercambiados.</li>
        <li>Las notas internas y etiquetas asociadas a tu contacto.</li>
    </ul>

    <h2>Qué podríamos conservar</h2>

    <p>
        Si realizaste un pago o te inscribiste en un curso, es posible que debamos
        conservar el registro contable de esa operación por el tiempo que exige la
        legislación fiscal venezolana. En ese caso conservamos únicamente el dato
        mínimo necesario para cumplir esa obligación, y nada más.
    </p>

    <h2>Plazos</h2>

    <ul>
        <li><strong>Confirmación de recibo:</strong> dentro de las 72 horas hábiles.</li>
        <li><strong>Eliminación completa:</strong> máximo 30 días desde la solicitud.</li>
        <li><strong>Confirmación final:</strong> te escribimos cuando el proceso haya terminado.</li>
    </ul>

    <h2>Contacto</h2>

    <ul>
        <li><strong>{{ $razonSocial }}</strong></li>
        <li>Correo: <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a></li>
        <li>Teléfono: {{ $telefono }}</li>
    </ul>

    <p>
        Si quieres conocer en detalle qué datos guardamos y por qué, revisa nuestra
        <a href="{{ route('legal.privacidad') }}">Política de Privacidad</a>.
    </p>

@endsection
