# Guía de contribución — Reino Aromas

Gracias por contribuir a este proyecto. Para mantener la calidad del código y facilitar la revisión, seguimos el flujo de trabajo descrito a continuación.

---

## Flujo de trabajo con Pull Requests

La rama `main` está **protegida**. Ningún cambio puede llegar directamente mediante `git push`; todos los cambios deben pasar por un **Pull Request (PR)**.

### Paso a paso

1. **Crea una rama** a partir de `main` con un nombre descriptivo:
   ```bash
   git checkout main
   git pull origin main
   git checkout -b feat/nombre-de-tu-feature
   ```

2. **Realiza los cambios** y haz commits con mensajes claros:
   ```bash
   git add .
   git commit -m "feat: descripción breve del cambio"
   ```

3. **Sube tu rama** al repositorio remoto:
   ```bash
   git push origin feat/nombre-de-tu-feature
   ```

4. **Abre un Pull Request** desde GitHub hacia `main`.

5. **Espera revisión**: al menos **1 aprobación** es obligatoria antes de poder mergear.

6. **Resuelve los comentarios** del revisor antes de hacer el merge.

7. Una vez aprobado y con todos los checks en verde, **mergea** el PR.

---

## Reglas de protección de rama

La rama `main` tiene configuradas las siguientes protecciones en GitHub:

| Regla | Estado |
|---|---|
| Require pull request before merging | ✅ Activo |
| Required approving reviews | ✅ Mínimo 1 aprobación |
| Dismiss stale reviews on new push | ✅ Activo |
| Require conversation resolution | ✅ Activo |
| Require status checks to pass (CI) | ⚙️ Se activa cuando existan workflows de CI |
| Restrict direct pushes to main | ✅ Activo |
| Include administrators | ✅ Activo |

La configuración completa está documentada en [`.github/branch-protection.yml`](.github/branch-protection.yml).

### Aplicar / actualizar la protección de rama

Si necesitas actualizar la regla de protección (por ejemplo, agregar nuevos checks de CI):

**Opción A — Interfaz web (GitHub UI)**

1. Ve a **Settings → Branches** en el repositorio.
2. Edita la regla de patrón `main`.
3. Ajusta las opciones según `.github/branch-protection.yml`.
4. Guarda los cambios.

**Opción B — GitHub API**

Con un token de acceso personal (PAT) que tenga el scope `repo` (necesario para gestionar protecciones de rama):

```bash
curl -X PUT https://api.github.com/repos/lpzzlnrd/Reino-Aromas/branches/main/protection \
  -H "Authorization: token <TU_TOKEN>" \
  -H "Accept: application/vnd.github+json" \
  -d @.github/branch-protection-payload.json
```

El payload de referencia está en [`.github/branch-protection-payload.json`](.github/branch-protection-payload.json).

---

## Convenciones de commits

Usamos [Conventional Commits](https://www.conventionalcommits.org/):

| Prefijo | Uso |
|---|---|
| `feat:` | Nueva funcionalidad |
| `fix:` | Corrección de bug |
| `docs:` | Solo cambios en documentación |
| `chore:` | Tareas de mantenimiento |
| `refactor:` | Refactorización sin cambio de comportamiento |

---

Si tienes dudas, abre un issue o contacta a los mantenedores del proyecto.
