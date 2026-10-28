---
status: accepted
---

# One error body for every error path

Error responses came in several shapes. A request body that failed validation answered `422 {"success":false,"error":"HTTP_ERROR","message":"Unprocessable Content"}`, so the client could not tell which field failed or why. Registration had its own 400 body with German messages, a failed login carried a `status` key, and "not authenticated" had three codes. The frontend could only guess its texts from messages that changed with every rewording.

Every error body is `{success: false, error: <CODE>, message}`. `error` comes from `ErrorCode`; `message` is English and meant for developers only, and the frontend picks the text it shows from the code. A code may add documented fields, such as `violations` on `REQUEST_VALIDATION_FAILED` or `suggestions` on `USERNAME_TAKEN`. No body has a `status` key; the HTTP status carries it.

A request body that fails validation answers 422 with `error: "REQUEST_VALIDATION_FAILED"` and `violations: [{field, code, message}]`, one entry per violation. `field` is the property path, `""` for a class-level violation. `code` comes from one table keyed by constraint class:

| Constraint | Code |
| --- | --- |
| `NotBlank`, `NotNull` | `REQUIRED` |
| `Length` | `TOO_SHORT` or `TOO_LONG`, by the limit that failed |
| `Email` | `INVALID_EMAIL` |
| `Choice` | `INVALID_CHOICE` |
| `Range`, `Positive`, `PositiveOrZero`, `Count` | `OUT_OF_RANGE` |
| `Type`, and a value the serializer cannot map to the property's type | `INVALID_TYPE` |
| `Unique` | `DUPLICATE` |
| a key the DTO does not declare (no constraint, one violation per key, `field` is the key) | `UNKNOWN_FIELD` |
| any other constraint | `INVALID` |

Nested constraints (`Sequentially`, `All`, `Collection`) report the inner constraint; a field that `Collection` misses reports `REQUIRED`. A constraint overrides the table with `payload: ['code' => '<CODE>']`, for example `EMAIL_DOMAIN_NOT_ALLOWED`. A body whose only serializer violations are unknown keys is still validated, so constraint violations appear next to `UNKNOWN_FIELD`; a body with a type error is not, because its object is only partly mapped. A body that is not valid JSON answers 400 `REQUEST_INVALID_JSON_BODY`.

## Considered Options

- Keep `HTTP_ERROR` and parse `message` on the client. Rejected: the message joins the violation texts into one string, without field names, and changes with every constraint message.
- Return Symfony's RFC 7807 `ConstraintViolationList` normalization. Rejected: it is a second error shape next to `{success, error, message}`, and its `type` URNs are Symfony's constraint UUIDs, which no client can read.
- Set the violation code per DTO. Rejected: every new DTO would need the setup, and the codes would drift apart.

## Consequences

- `ErrorController` renders the violations of every `#[MapRequestPayload]` failure; no DTO needs setup. The table lives in `App\Http\ViolationCode`.
- OpenAPI documents the body once, as the `RequestValidationFailed` schema, and every `#[MapRequestPayload]` operation references it in its 422 response.
- Registration moves onto this body in #176; until then it keeps its own 400 body. `#[MapQueryParameter]` failures keep their current handling.
- The frontend reads errors by code (IliasAlmerekov/darts#371). Backend changes from #45 merge one by one, but deploy together with that frontend release.
