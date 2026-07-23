const { neon } = require('@neondatabase/serverless');
const sgMail = require('@sendgrid/mail');

const sql = neon(process.env.DATABASE_URL);

const GROUPS = {
  A: {
    sucursales: ['R&F Pilar', 'R&F Unicenter', 'R&F Palermo'],
    apiKey: process.env.SENDGRID_API_KEY_GROUP_A,
    templateId: process.env.SENDGRID_TEMPLATE_ID_GROUP_A,
    senderEmail: process.env.SENDGRID_SENDER_EMAIL_GROUP_A,
    senderName: process.env.SENDGRID_SENDER_NAME_GROUP_A
  },
  B: {
    sucursales: ['R&F Alto Rosario', 'R&F Oroño', 'R&F Savoy'],
    apiKey: process.env.SENDGRID_API_KEY_GROUP_B,
    templateId: process.env.SENDGRID_TEMPLATE_ID_GROUP_B,
    senderEmail: process.env.SENDGRID_SENDER_EMAIL_GROUP_B,
    senderName: process.env.SENDGRID_SENDER_NAME_GROUP_B
  }
};

const ALL_SUCURSALES = [...GROUPS.A.sucursales, ...GROUPS.B.sucursales];

const RATE_LIMIT_PER_MINUTE = 5;
const RATE_LIMIT_PER_HOUR = 20;

const EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
const DATE_RE = /^\d{4}-\d{2}-\d{2}$/;

function isBirthdayToday(fecha) {
  const parts = new Intl.DateTimeFormat('en-US', {
    timeZone: 'America/Argentina/Buenos_Aires',
    month: '2-digit',
    day: '2-digit'
  }).formatToParts(new Date());
  const month = parts.find(function (p) { return p.type === 'month'; }).value;
  const day = parts.find(function (p) { return p.type === 'day'; }).value;
  return fecha.slice(5) === (month + '-' + day);
}

function jsonResponse(statusCode, body) {
  return {
    statusCode,
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(body)
  };
}

function getClientIp(event) {
  if (event.headers['x-nf-client-connection-ip']) {
    return event.headers['x-nf-client-connection-ip'];
  }
  const forwarded = event.headers['x-forwarded-for'];
  if (forwarded) {
    return forwarded.split(',')[0].trim();
  }
  return 'unknown';
}

function groupForSucursal(sucursal) {
  if (GROUPS.A.sucursales.includes(sucursal)) return GROUPS.A;
  if (GROUPS.B.sucursales.includes(sucursal)) return GROUPS.B;
  return null;
}

exports.handler = async function (event) {
  if (event.httpMethod !== 'POST') {
    return jsonResponse(405, { ok: false, reason: 'method_not_allowed' });
  }

  let payload;
  try {
    payload = JSON.parse(event.body || '{}');
  } catch (err) {
    return jsonResponse(400, { ok: false, reason: 'invalid_json' });
  }

  const { nombre, fecha, email, sucursal, terminos, website } = payload;

  // Honeypot: si un bot completó este campo oculto, respondemos como si
  // hubiera funcionado, sin tocar la base ni enviar nada.
  if (website) {
    return jsonResponse(200, { ok: true });
  }

  if (
    typeof nombre !== 'string' || !nombre.trim() ||
    typeof fecha !== 'string' || !DATE_RE.test(fecha.trim()) ||
    typeof email !== 'string' || !EMAIL_RE.test(email.trim()) ||
    typeof sucursal !== 'string' || !ALL_SUCURSALES.includes(sucursal) ||
    !terminos
  ) {
    return jsonResponse(400, { ok: false, reason: 'invalid_input' });
  }

  if (!isBirthdayToday(fecha.trim())) {
    return jsonResponse(403, { ok: false, reason: 'not_birthday' });
  }

  const cleanEmail = email.trim().toLowerCase();
  const cleanNombre = nombre.trim();
  const ip = getClientIp(event);
  const userAgent = event.headers['user-agent'] || null;

  // ── Rate limit por IP ──
  const [{ count: countMinute }] = await sql`
    SELECT COUNT(*)::int AS count FROM submission_attempts
    WHERE ip = ${ip} AND created_at > now() - interval '1 minute'
  `;
  const [{ count: countHour }] = await sql`
    SELECT COUNT(*)::int AS count FROM submission_attempts
    WHERE ip = ${ip} AND created_at > now() - interval '1 hour'
  `;

  if (countMinute >= RATE_LIMIT_PER_MINUTE || countHour >= RATE_LIMIT_PER_HOUR) {
    return jsonResponse(429, { ok: false, reason: 'rate_limited' });
  }

  await sql`INSERT INTO submission_attempts (ip) VALUES (${ip})`;

  // ── Duplicado / elegibilidad (una vez por año, desde el aniversario del último envío) ──
  const existing = await sql`
    SELECT claimed_at FROM birthday_claims WHERE email = ${cleanEmail}
  `;

  if (existing.length > 0) {
    const [{ next_eligible: nextEligible, eligible }] = await sql`
      SELECT
        (claimed_at::date + interval '1 year') AS next_eligible,
        now() >= (claimed_at::date + interval '1 year') AS eligible
      FROM birthday_claims WHERE email = ${cleanEmail}
    `;
    if (!eligible) {
      return jsonResponse(409, {
        ok: false,
        reason: 'already_claimed',
        nextEligibleDate: nextEligible
      });
    }
  }

  const group = groupForSucursal(sucursal);

  await sql`
    INSERT INTO birthday_claims (email, nombre, fecha_nacimiento, sucursal, acepta_marketing, ip, user_agent, claimed_at)
    VALUES (${cleanEmail}, ${cleanNombre}, ${fecha}, ${sucursal}, ${true}, ${ip}, ${userAgent}, now())
    ON CONFLICT (email) DO UPDATE SET
      nombre = excluded.nombre,
      fecha_nacimiento = excluded.fecha_nacimiento,
      sucursal = excluded.sucursal,
      acepta_marketing = excluded.acepta_marketing,
      ip = excluded.ip,
      user_agent = excluded.user_agent,
      claimed_at = excluded.claimed_at
  `;

  // ── Email de confirmación (no bloquea la respuesta si falla) ──
  if (group && group.apiKey && group.templateId && group.senderEmail) {
    try {
      sgMail.setApiKey(group.apiKey);
      await sgMail.send({
        to: cleanEmail,
        from: { email: group.senderEmail, name: group.senderName || undefined },
        templateId: group.templateId,
        dynamicTemplateData: {
          nombre: cleanNombre,
          sucursal: sucursal,
          fecha: fecha
        }
      });
    } catch (err) {
      console.error('Error enviando email de confirmación vía SendGrid:', err);
    }
  } else {
    console.error('Configuración de SendGrid incompleta para la sucursal:', sucursal);
  }

  return jsonResponse(200, { ok: true });
};
