<?php

namespace App\Adapters\Inbound\Http;

use App\Application\Ports\TokenIssuer;
use App\Application\Services;
use App\Domain\Book;
use App\Domain\DateRange;
use App\Domain\Loan;
use App\Domain\LoanItem;
use App\Domain\ValidationFailed;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/* ---------------------------------------------------------------------------------------------
 * Input: request parsing and validation. User messages in Spanish, field names in English.
 * ------------------------------------------------------------------------------------------- */
final class Input
{
    public const MAX_SIZE = 50;

    public static function body(Request $r): array
    {
        $data = $r->isJson() ? $r->json()->all() : $r->all();

        return is_array($data) ? $data : [];
    }

    public static function str(array $in, string $field, int $max, bool $required = true): ?string
    {
        $v = $in[$field] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                throw new ValidationFailed("El campo {$field} es obligatorio.", [['field' => $field, 'issue' => 'obligatorio']]);
            }

            return null;
        }
        if (! is_string($v)) {
            throw new ValidationFailed("El campo {$field} debe ser texto.", [['field' => $field, 'issue' => 'debe ser texto']]);
        }
        if (mb_strlen(trim($v)) > $max) {
            throw new ValidationFailed("El campo {$field} admite hasta {$max} caracteres.", [['field' => $field, 'issue' => "máximo {$max} caracteres"]]);
        }

        return $v;
    }

    public static function int(array $in, string $field): int
    {
        $v = $in[$field] ?? null;
        if (is_int($v) || (is_string($v) && preg_match('/^-?\d{1,9}$/', $v))) {
            return (int) $v;
        }
        throw new ValidationFailed("El campo {$field} debe ser un número entero.", [['field' => $field, 'issue' => 'entero obligatorio']]);
    }

    public static function uuid(array $in, string $field, bool $required = true): ?string
    {
        $v = $in[$field] ?? null;
        if (($v === null || $v === '') && ! $required) {
            return null;
        }
        if (! is_string($v) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v)) {
            throw new ValidationFailed("El campo {$field} debe ser un UUID.", [['field' => $field, 'issue' => 'UUID inválido']]);
        }

        return strtolower($v);
    }

    public static function date(array $in, string $field, bool $required): ?\DateTimeImmutable
    {
        $v = $in[$field] ?? null;
        if ($v === null || $v === '') {
            if ($required) {
                throw new ValidationFailed("El campo {$field} es obligatorio (AAAA-MM-DD).", [['field' => $field, 'issue' => 'obligatorio']]);
            }

            return null;
        }
        $d = is_string($v) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $v, new \DateTimeZone('UTC')) : false;
        if ($d === false || $d->format('Y-m-d') !== $v) {
            throw new ValidationFailed("El campo {$field} debe ser una fecha AAAA-MM-DD.", [['field' => $field, 'issue' => 'fecha inválida']]);
        }

        return $d;
    }

    /** `?page=1&size=20`; size max 50. Exceeding it is not silently clamped: 422. @return array{int, int} */
    public static function page(Request $r): array
    {
        $q = $r->query();
        $page = isset($q['page']) ? self::int($q, 'page') : 1;
        $size = isset($q['size']) ? self::int($q, 'size') : 20;
        if ($page < 1) {
            throw new ValidationFailed('page debe ser ≥ 1.', [['field' => 'page', 'issue' => 'mínimo 1']]);
        }
        if ($size < 1 || $size > self::MAX_SIZE) {
            throw new ValidationFailed('size debe estar entre 1 y 50.', [['field' => 'size', 'issue' => 'entre 1 y 50']]);
        }

        return [$page, $size];
    }
}

/* ---------------------------------------------------------------------------------------------
 * Output: contract JSON shape. ISO 8601 UTC dates with Z and no microseconds.
 * ------------------------------------------------------------------------------------------- */
final class Present
{
    public static function at(?\DateTimeImmutable $t): ?string
    {
        return $t?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }

    public static function page(array $items, int $page, int $size, int $total): array
    {
        return ['items' => $items, 'page' => $page, 'size' => $size, 'total' => $total];
    }

    public static function book(Book $b): array
    {
        return [
            'id' => $b->id, 'title' => $b->title, 'author' => $b->author, 'isbn' => $b->isbn->value,
            'category' => ['id' => $b->categoryId, 'name' => $b->categoryName],
            'total_copies' => $b->totalCopies(), 'available_copies' => $b->availableCopies(),
            'cover_key' => $b->coverKey, // null, never an empty string
        ];
    }

    public static function item(LoanItem $i): array
    {
        return [
            'id' => $i->id, 'book_id' => $i->bookId, 'book_title' => $i->bookTitle, 'isbn' => $i->isbn,
            'category_name' => $i->categoryName, 'due_at' => self::at($i->dueAt), 'returned_at' => self::at($i->returnedAt),
        ];
    }

    public static function loan(Loan $l, \DateTimeImmutable $now): array
    {
        return [
            'id' => $l->id, 'member_id' => $l->memberId, 'loaned_at' => self::at($l->loanedAt), 'status' => $l->status($now),
            'items' => array_map(fn ($i) => self::item($i), $l->items()),
        ];
    }
}

/* ---------------------------------------------------------------------------------------------
 * Middleware: token authentication and role authorization.
 * ------------------------------------------------------------------------------------------- */
final class AuthenticateToken
{
    public function __construct(private TokenIssuer $tokens) {}

    public function handle(Request $request, Closure $next)
    {
        $header = (string) $request->header('Authorization', '');
        $claims = str_starts_with($header, 'Bearer ') ? $this->tokens->verify(substr($header, 7)) : null;
        if ($claims === null) {
            return ErrorBody::json(401, 'UNAUTHENTICATED', 'Falta el token o no es válido.');
        }
        $request->attributes->set('user_id', $claims['sub']);
        $request->attributes->set('role', $claims['role']);

        return $next($request);
    }
}

/** Evaluated before looking up the resource: a 403 never reveals whether the resource exists (api-contract §4). */
final class RequireRole
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        if (! in_array($request->attributes->get('role'), $roles, true)) {
            return ErrorBody::json(403, 'FORBIDDEN_ROLE', 'Tu rol no permite esta operación.');
        }

        return $next($request);
    }
}

/** Minimal multipart/form-data reader for PUT (PHP only parses multipart on POST). */
final class Multipart
{
    public static function field(Request $r, string $name): ?string
    {
        if (! preg_match('/boundary="?([^";]+)"?/i', (string) $r->header('Content-Type'), $m)) {
            return null;
        }
        foreach (explode('--'.$m[1], $r->getContent()) as $part) {
            $split = strpos($part, "\r\n\r\n");
            if ($split === false) {
                continue;
            }
            $headers = substr($part, 0, $split);
            if (preg_match('/name="'.preg_quote($name, '/').'"/i', $headers)) {
                return substr($part, $split + 4, -2); // drop the trailing \r\n before the next boundary
            }
        }

        return null;
    }
}

final class ErrorBody
{
    public static function json(int $status, string $code, string $message, array $details = []): JsonResponse
    {
        return new JsonResponse(['code' => $code, 'message' => $message, 'details' => $details], $status, [], JSON_UNESCAPED_UNICODE);
    }
}

/* ---------------------------------------------------------------------------------------------
 * Controllers: translate HTTP <-> use cases. No business rules here.
 * ------------------------------------------------------------------------------------------- */
final class HealthController
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['status' => 'ok']);
    }
}

final class AuthController
{
    public function login(Request $r, Services\LoginService $svc): JsonResponse
    {
        $in = Input::body($r);
        $username = is_string($in['username'] ?? null) ? $in['username'] : '';
        $password = is_string($in['password'] ?? null) ? $in['password'] : '';

        return response()->json($svc->login($username, $password));
    }
}

final class UserController
{
    public function store(Request $r, Services\CreateLibrarianService $svc): JsonResponse
    {
        $in = Input::body($r);
        $role = Input::str($in, 'role', 40);
        $user = $svc->create(Input::str($in, 'username', 200), (string) Input::str($in, 'password', 1024), $role);

        return response()->json($user, 201);
    }
}

final class CategoryController
{
    public function index(\App\Application\Ports\CategoryRepository $categories): JsonResponse
    {
        return response()->json(['items' => $categories->all()], 200, [], JSON_UNESCAPED_UNICODE);
    }
}

final class BookController
{
    public function index(Request $r, Services\SearchBooksService $svc): JsonResponse
    {
        [$page, $size] = Input::page($r);
        $q = $r->query();
        $res = $svc->search(Input::str($q, 'q', 250, false), Input::uuid($q, 'category_id', false), $page, $size);

        return response()->json(Present::page(array_map(fn ($b) => Present::book($b), $res['items']), $page, $size, $res['total']), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $id, Services\SearchBooksService $svc): JsonResponse
    {
        return response()->json(Present::book($svc->get($id)), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $r, Services\CreateBookService $svc): JsonResponse
    {
        $in = Input::body($r);
        $book = $svc->create(
            Input::str($in, 'title', 250), Input::str($in, 'author', 200), Input::str($in, 'isbn', 13),
            Input::uuid($in, 'category_id'), Input::int($in, 'total_copies'),
        );

        return response()->json(Present::book($book), 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function update(string $id, Request $r, Services\UpdateBookService $svc): JsonResponse
    {
        $in = Input::body($r);
        $book = $svc->update(
            $id, Input::str($in, 'title', 250), Input::str($in, 'author', 200), Input::uuid($in, 'category_id'),
            Input::int($in, 'total_copies'), Input::str($in, 'isbn', 13, false),
        );

        return response()->json(Present::book($book), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function destroy(string $id, Services\DeleteBookService $svc): Response
    {
        $svc->delete($id);

        return response()->noContent();
    }

    public function putCover(string $id, Request $r, Services\BookCoverService $svc): JsonResponse
    {
        // PHP only fills $_FILES on POST: a multipart PUT (what the contract asks for) must be read from the raw body.
        $file = $r->file('file');
        $bytes = ($file !== null && $file->isValid()) ? (string) file_get_contents($file->getRealPath()) : Multipart::field($r, 'file');
        if ($bytes === null || $bytes === '') {
            throw new ValidationFailed('Adjunta la portada en el campo file (JPG o PNG, máx. 2 MB).', [['field' => 'file', 'issue' => 'obligatorio']]);
        }
        if (strlen($bytes) > Services\BookCoverService::MAX_BYTES) {
            throw new ValidationFailed('La portada no puede superar 2 MB.', [['field' => 'file', 'issue' => 'máximo 2 MB']]);
        }
        // The type is validated by CONTENT (file signature), not by extension nor by the client's Content-Type.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes) ?: '';

        return response()->json(Present::book($svc->upload($id, $bytes, $mime)), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function getCover(string $id, Services\BookCoverService $svc): Response
    {
        $cover = $svc->read($id);

        return response($cover['bytes'], 200, ['Content-Type' => $cover['mime'], 'Cache-Control' => 'private, max-age=300']);
    }

    public function deleteCover(string $id, Services\BookCoverService $svc): Response
    {
        $svc->remove($id);

        return response()->noContent();
    }
}

final class MemberController
{
    public function index(Request $r, Services\MemberService $svc): JsonResponse
    {
        [$page, $size] = Input::page($r);
        $res = $svc->search(Input::str($r->query(), 'q', 200, false), $page, $size);

        return response()->json(Present::page($res['items'], $page, $size, $res['total']), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $id, Services\MemberService $svc): JsonResponse
    {
        return response()->json($svc->get($id), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $r, Services\MemberService $svc): JsonResponse
    {
        $in = Input::body($r);

        return response()->json($svc->create(Input::str($in, 'full_name', 200), Input::str($in, 'document_number', 30)), 201, [], JSON_UNESCAPED_UNICODE);
    }
}

final class LoanController
{
    public function store(Request $r, Services\LendBooksService $svc, Services\LoanQueryService $q): JsonResponse
    {
        $in = Input::body($r);
        $memberId = Input::uuid($in, 'member_id');
        $items = $in['items'] ?? null;
        if (! is_array($items) || ! array_is_list($items)) {
            throw new ValidationFailed('items debe ser una lista de 1 a 3 libros.', [['field' => 'items', 'issue' => 'lista obligatoria']]);
        }
        $bookIds = [];
        foreach ($items as $i => $item) {
            $bookIds[] = Input::uuid(is_array($item) ? $item : [], 'book_id') ?? '';
        }
        $loan = $svc->lend($memberId, $bookIds, (string) $r->attributes->get('user_id'));

        return response()->json(Present::loan($loan, $q->now()), 201, [], JSON_UNESCAPED_UNICODE);
    }

    public function index(Request $r, Services\LoanQueryService $svc): JsonResponse
    {
        [$page, $size] = Input::page($r);
        $q = $r->query();
        $from = Input::date($q, 'from', false);
        $to = Input::date($q, 'to', false);
        $range = null;
        if ($from !== null || $to !== null) {
            $range = DateRange::of($from ?? new \DateTimeImmutable('1970-01-01', new \DateTimeZone('UTC')), $to ?? new \DateTimeImmutable('9999-12-30', new \DateTimeZone('UTC')));
        }
        $status = Input::str($q, 'status', 10, false);
        if ($status !== null && ! in_array($status, ['open', 'overdue', 'closed'], true)) {
            throw new ValidationFailed('status admite open, overdue o closed.', [['field' => 'status', 'issue' => 'valor no permitido']]);
        }
        $res = $svc->list($range, $status, Input::uuid($q, 'member_id', false), $page, $size);
        $now = $svc->now();

        return response()->json(Present::page(array_map(fn ($l) => Present::loan($l, $now), $res['items']), $page, $size, $res['total']), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function show(string $id, Services\LoanQueryService $svc): JsonResponse
    {
        return response()->json(Present::loan($svc->get($id), $svc->now()), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function returnItem(string $loanId, string $itemId, Services\ReturnItemService $svc): JsonResponse
    {
        return response()->json(Present::item($svc->return($loanId, $itemId)), 200, [], JSON_UNESCAPED_UNICODE);
    }

    public function overdue(Request $r, Services\OverdueService $svc): JsonResponse
    {
        [$page, $size] = Input::page($r);
        $res = $svc->list($page, $size);
        $items = array_map(fn ($i) => array_replace($i, ['due_at' => Present::at($i['due_at'])]), $res['items']);

        return response()->json(Present::page($items, $page, $size, $res['total']), 200, [], JSON_UNESCAPED_UNICODE);
    }
}

final class ReportController
{
    public function mostBorrowed(Request $r, Services\MostBorrowedReportService $svc): JsonResponse
    {
        $q = $r->query();
        $range = DateRange::of(Input::date($q, 'from', true), Input::date($q, 'to', true));

        return response()->json([
            'from' => $range->from->format('Y-m-d'),
            'to' => $range->to->format('Y-m-d'),
            'rows' => $svc->run($range),
        ], 200, [], JSON_UNESCAPED_UNICODE);
    }
}
