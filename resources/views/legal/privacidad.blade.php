@extends('legal.layout')

@section('title', 'Política de Privacidad')
@section('meta_description', 'Cómo Reino Aromas recopila, usa y protege tu información personal cuando nos escribes por WhatsApp, Instagram o Facebook.')

@section('content')

    <p>
        En Reino Aromas cuidamos tu información con el mismo esmero con el que
        preparamos nuestros cursos. Este documento explica, sin rodeos, qué datos
        recibimos cuando nos escribes, para qué los usamos y cómo puedes pedirnos
        que los borremos.
    </p>

    <h2>1. Quiénes somos</h2>

    <p>
        <strong>{{ $razonSocial }}</strong>@if($rif), RIF {{ $rif }}@endif es una
        empresa venezolana dedicada a la formación en oficios artesanales
        —elaboración de velas, jabones, difusores, sales aromáticas y mantequilla
        corporal— y a la venta de insumos para su elaboración. Atendemos en
        Caracas, Valencia, Barquisimeto, Maracay y Margarita.
    </p>

    <p>
        Somos los responsables del tratamiento de tus datos personales:
    </p>

    <ul>
        <li><strong>Correo:</strong> <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a></li>
        <li><strong>Teléfono:</strong> {{ $telefono }}</li>
        <li><strong>Dirección:</strong> {{ $direccion }}</li>
    </ul>

    <h2>2. Qué información recopilamos</h2>

    <p>
        Cuando nos contactas por WhatsApp, Instagram o Facebook, recibimos y
        almacenamos:
    </p>

    <ul>
        <li>
            <strong>Datos de identificación:</strong> tu nombre o nombre de usuario,
            tal como aparece en la plataforma desde la que nos escribes.
        </li>
        <li>
            <strong>Datos de contacto:</strong> tu número de teléfono (en el caso de
            WhatsApp) o tu identificador de cuenta (en Instagram y Facebook).
        </li>
        <li>
            <strong>Contenido de las conversaciones:</strong> los mensajes que nos
            envías y las respuestas de nuestro equipo, incluidas las imágenes,
            audios y documentos que compartas con nosotros.
        </li>
        <li>
            <strong>Foto de perfil pública</strong>, cuando la plataforma la pone a
            nuestra disposición.
        </li>
        <li>
            <strong>Datos comerciales:</strong> la ciudad desde la que nos escribes y
            el curso o producto por el que consultas, para poder atenderte
            correctamente.
        </li>
    </ul>

    <div class="legal-note">
        <p>
            <strong>No recopilamos</strong> datos de tarjetas de crédito,
            contraseñas ni documentos de identidad a través de estos canales. Si
            alguien te los pide diciendo ser de Reino Aromas, desconfía y
            escríbenos.
        </p>
    </div>

    <h2>3. Para qué usamos tu información</h2>

    <p>Usamos tu información únicamente para:</p>

    <ul>
        <li>Responder tus consultas sobre cursos, precios, fechas y disponibilidad.</li>
        <li>Gestionar tu inscripción a un curso o tu pedido de insumos.</li>
        <li>
            Mantener el historial de la conversación, para que cualquier persona de
            nuestro equipo pueda continuar atendiéndote sin que tengas que repetir
            lo que ya contaste.
        </li>
        <li>
            Enviarte confirmaciones y recordatorios relacionados con una inscripción
            o pedido que tú hayas solicitado.
        </li>
    </ul>

    <p>
        <strong>No usamos tu información para publicidad dirigida, no la vendemos,
        y no la compartimos con terceros con fines comerciales.</strong>
    </p>

    <h2>4. Base legal del tratamiento</h2>

    <p>
        Tratamos tus datos porque tú iniciaste voluntariamente una conversación con
        nosotros para solicitar información o un servicio. Puedes retirar ese
        consentimiento cuando quieras escribiéndonos.
    </p>

    <h2>5. Con quién compartimos tu información</h2>

    <p>Compartimos datos únicamente con:</p>

    <ul>
        <li>
            <strong>Meta Platforms, Inc.</strong> — como proveedor de la
            infraestructura de WhatsApp Business Platform e Instagram Messaging, por
            la cual transitan los mensajes. El tratamiento que Meta hace de esos
            datos se rige por su propia política de privacidad.
        </li>
        <li>
            <strong>Nuestro proveedor de alojamiento</strong> — donde se almacena
            nuestra base de datos, en servidores con acceso restringido.
        </li>
    </ul>

    <p>
        No compartimos tu información con anunciantes, corredores de datos ni
        ninguna otra empresa.
    </p>

    <h2>6. Cuánto tiempo conservamos tu información</h2>

    <p>
        Conservamos las conversaciones mientras exista una relación comercial
        activa y hasta <strong>24 meses</strong> después del último contacto.
        Cumplido ese plazo, los datos se eliminan de forma permanente, salvo que
        una obligación legal nos exija conservarlos por más tiempo.
    </p>

    <h2>7. Cómo protegemos tu información</h2>

    <ul>
        <li>Todas las comunicaciones con nuestro sistema viajan cifradas mediante HTTPS.</li>
        <li>
            El acceso a nuestro sistema de atención está restringido al personal
            autorizado, con usuario y contraseña individuales.
        </li>
        <li>
            Los mensajes entrantes se validan criptográficamente para confirmar que
            provienen de Meta y no de un tercero.
        </li>
    </ul>

    <h2>8. Tus derechos</h2>

    <p>En cualquier momento y sin costo, puedes:</p>

    <ul>
        <li><strong>Acceder</strong> a los datos que tenemos sobre ti.</li>
        <li><strong>Rectificar</strong> los datos que estén incorrectos.</li>
        <li><strong>Solicitar la eliminación</strong> de tus datos.</li>
        <li><strong>Oponerte</strong> a que sigamos tratando tu información.</li>
        <li><strong>Solicitar una copia</strong> de tus datos en formato legible.</li>
    </ul>

    <p>
        Para ejercer cualquiera de estos derechos escribe a
        <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a>.
        Respondemos en un plazo máximo de <strong>30 días</strong>.
    </p>

    <h2>9. Cómo solicitar la eliminación de tus datos</h2>

    <p>Puedes pedir que borremos toda tu información por cualquiera de estas vías:</p>

    <ol>
        <li>
            Escribiendo a <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a>
            con el asunto «Eliminación de datos», indicando el número de teléfono o
            el usuario desde el que nos contactaste.
        </li>
        <li>
            Enviándonos un mensaje por el mismo canal por el que nos escribiste, con
            el texto «Solicito la eliminación de mis datos».
        </li>
        <li>
            Siguiendo las instrucciones detalladas en nuestra página de
            <a href="{{ route('legal.eliminacion-datos') }}">eliminación de datos</a>.
        </li>
    </ol>

    <p>
        Procesamos estas solicitudes en un plazo máximo de <strong>30 días</strong>
        y te confirmamos por escrito cuando la eliminación se haya completado.
    </p>

    <h2>10. Menores de edad</h2>

    <p>
        Nuestros servicios están dirigidos a personas mayores de 18 años. No
        recopilamos intencionalmente datos de menores. Si detectamos que hemos
        recibido información de un menor, la eliminamos de inmediato.
    </p>

    <h2>11. Cambios en esta política</h2>

    <p>
        Si modificamos esta política, publicaremos la versión actualizada en esta
        misma dirección con una nueva fecha de última actualización. Los cambios
        importantes se comunican por el canal habitual de contacto.
    </p>

    <h2>12. Contacto</h2>

    <p>
        Para cualquier duda sobre esta política o sobre el tratamiento de tus
        datos:
    </p>

    <ul>
        <li><strong>{{ $razonSocial }}</strong></li>
        <li>Correo: <a href="mailto:{{ $correoContacto }}">{{ $correoContacto }}</a></li>
        <li>Teléfono: {{ $telefono }}</li>
        <li>Dirección: {{ $direccion }}</li>
    </ul>

@endsection
