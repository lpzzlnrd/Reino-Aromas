@extends('legal.layout')

@section('title', 'Términos y Condiciones')
@section('meta_description', 'Condiciones de uso de los canales de atención de Reino Aromas: cursos artesanales, venta de insumos, inscripciones y pagos.')

@section('content')

    <p>
        Estas son las reglas del juego cuando nos escribes o usas nuestros
        servicios. Las escribimos en lenguaje claro para que no tengas que
        descifrar nada.
    </p>

    <h2>1. Aceptación</h2>

    <p>
        Al comunicarte con Reino Aromas a través de WhatsApp, Instagram, Facebook o
        nuestro sitio web, aceptas estos términos. Si no estás de acuerdo con
        ellos, te pedimos abstenerte de usar estos canales.
    </p>

    <h2>2. Qué ofrecemos</h2>

    <ul>
        <li>
            <strong>Cursos presenciales de oficios artesanales:</strong> elaboración
            de velas, jabones, difusores, sales aromáticas y mantequilla corporal.
        </li>
        <li>
            <strong>Venta de insumos:</strong> ceras de soja, fragancias, mechas,
            moldes y materiales relacionados.
        </li>
    </ul>

    <p>
        Nuestros canales de mensajería se usan exclusivamente para atención al
        cliente: consultas, inscripciones y coordinación de pedidos.
    </p>

    <h2>3. Uso aceptable</h2>

    <p>Al comunicarte con nosotros, te comprometes a <strong>no</strong>:</p>

    <ul>
        <li>
            Enviar contenido ilegal, ofensivo, discriminatorio, amenazante o que
            incite a la violencia.
        </li>
        <li>Enviar spam, publicidad no solicitada o mensajes masivos automatizados.</li>
        <li>Suplantar la identidad de otra persona o empresa.</li>
        <li>
            Intentar acceder sin autorización a nuestros sistemas o interferir con
            su funcionamiento.
        </li>
        <li>
            Usar nuestros canales para actividades fraudulentas o para promocionar
            productos de terceros.
        </li>
    </ul>

    <p>
        Nos reservamos el derecho de dejar de atender y bloquear a quien incumpla
        estas condiciones.
    </p>

    <h2>4. Inscripciones y pagos</h2>

    <ul>
        <li>
            Los precios de cursos e insumos varían según la ciudad y se informan al
            momento de la consulta.
        </li>
        <li>
            Una inscripción se considera confirmada únicamente cuando hemos recibido
            el pago y te hemos enviado la confirmación correspondiente.
        </li>
        <li>
            Las condiciones de cancelación, reprogramación y devolución se informan
            al confirmar la inscripción.
        </li>
        <li>
            Los pagos se procesan por los medios que indiquemos en cada caso.
        </li>
    </ul>

    <div class="legal-note">
        <p>
            <strong>Nunca solicitamos datos de tarjetas de crédito ni contraseñas
            por mensajería.</strong> Si recibes un mensaje pidiéndote esa
            información en nombre de Reino Aromas, no respondas y avísanos.
        </p>
    </div>

    <h2>5. Disponibilidad y horarios</h2>

    <p>
        Atendemos en horario laboral. Los mensajes que recibimos fuera de ese
        horario se responden el siguiente día hábil. No garantizamos disponibilidad
        ininterrumpida de los canales, ya que dependen de servicios de terceros
        (Meta) sobre los que no tenemos control.
    </p>

    <h2>6. Comunicaciones por WhatsApp e Instagram</h2>

    <ul>
        <li>
            Solo te escribimos si tú iniciaste la conversación o si solicitaste que
            te contactáramos.
        </li>
        <li>
            Puedes pedirnos que dejemos de escribirte en cualquier momento
            respondiendo con <strong>«BAJA»</strong> o <strong>«STOP»</strong>, y
            dejaremos de enviarte mensajes.
        </li>
        <li>
            Estos servicios son operados por Meta Platforms, Inc. y su uso está
            también sujeto a los términos de Meta.
        </li>
    </ul>

    <h2>7. Propiedad intelectual</h2>

    <p>
        El contenido de nuestros cursos, materiales didácticos, fotografías, textos
        y logotipos son propiedad de Reino Aromas. No está permitido reproducirlos,
        distribuirlos ni usarlos comercialmente sin autorización escrita.
    </p>

    <h2>8. Limitación de responsabilidad</h2>

    <p>
        Nuestros cursos entregan formación e información general. Reino Aromas no
        se responsabiliza por el uso que cada participante haga de las técnicas
        aprendidas ni por los resultados de productos elaborados por cuenta propia.
    </p>

    <div class="legal-note">
        <p>
            La elaboración de productos artesanales implica el manejo de materiales
            calientes y sustancias químicas. Es responsabilidad de cada persona
            seguir las medidas de seguridad indicadas durante la formación.
        </p>
    </div>

    <h2>9. Protección de datos</h2>

    <p>
        El tratamiento de tu información personal se rige por nuestra
        <a href="{{ route('legal.privacidad') }}">Política de Privacidad</a>.
    </p>

    <h2>10. Modificaciones</h2>

    <p>
        Podemos actualizar estos términos en cualquier momento. La versión vigente
        será siempre la publicada en esta dirección.
    </p>

    <h2>11. Legislación aplicable</h2>

    <p>
        Estos términos se rigen por las leyes de la República Bolivariana de
        Venezuela. Cualquier controversia se someterá a los tribunales competentes
        de {{ $ciudadJurisdiccion }}.
    </p>

    <h2>12. Contacto</h2>

    <ul>
        <li><strong>{{ $razonSocial }}</strong></li>
        <li>Correo: <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a></li>
        <li>Teléfono: {{ $telefono }}</li>
        <li>Dirección: {{ $direccion }}</li>
    </ul>

@endsection
