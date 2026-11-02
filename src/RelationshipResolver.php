<?php
declare(strict_types=1);
namespace Light\Entity;

use Light\Entity\Schema\SchemaReader;

/** Context-scoped FK metadata and getter resolution. */
final class RelationshipResolver
{
    private array $foreignKeys = [];
    private array $tables = [];
    private array $overrides = [];
    private array $dynamic = [];

    public function __construct(private SchemaReader $schema, private IModel $model) {}

    public function configure(string $class, string $name, string $table, array $columns, ?string $targetClass): void
    {
        $this->overrides[$class][$this->normalize($name)] = [$table, $columns, $targetClass];
    }

    public function resolve(EntityMapping $source, string $name): array
    {
        $override = $this->overrides[$source->class][$this->normalize($name)] ?? null;
        $candidates = [];
        foreach ($this->tables[$source->table] ??= $this->schema->tables($source->table) as $table) {
            foreach ($this->foreignKeys[$table] ??= $this->schema->foreignKeys($table) as $fk) {
                $directions = [];
                if ($table === $source->table) { $directions[] = false; }
                if ($fk['table'] === $source->table) { $directions[] = true; }
                foreach ($directions as $inverse) {
                    $target = $inverse ? $table : $fk['table'];
                    $many = $inverse && !$fk['unique'];
                    $base = basename(str_replace('.', '/', $target));
                    $singular = $this->singular($base);
                    $aliases = [$many ? $base : $singular];
                    if (!$many && count($fk['local']) === 1 && !$inverse) {
                        $aliases[] = preg_replace('/_id$/i', '', $fk['local'][0]);
                    }
                    if ($override !== null) {
                        if ($override[0] !== $target || $override[1] !== $fk['local']) { continue; }
                    } elseif (!in_array($this->normalize($name), array_map($this->normalize(...), $aliases), true)) {
                        continue;
                    }
                    $candidates[] = ['table' => $target, 'many' => $many,
                        'local' => $inverse ? $fk['remote'] : $fk['local'],
                        'remote' => $inverse ? $fk['local'] : $fk['remote']];
                }
            }
        }
        if (count($candidates) !== 1) {
            throw new \BadMethodCallException(count($candidates) === 0
                ? "Unknown relationship: get{$name}"
                : "Ambiguous relationship: get{$name}; configure an explicit FK mapping.");
        }
        $relation = $candidates[0];
        $relation['mapping'] = $this->mapping($source, $relation['table'], $override[2] ?? null);
        return $relation;
    }

    private function mapping(EntityMapping $source, string $table, ?string $class): EntityMapping
    {
        if ($class !== null) { return $this->model->getEntityType($class, $table); }
        $matches = array_values(array_filter($this->model->getEntityTypes(), fn ($m) => $m->table === $table));
        if (count($matches) > 1) { throw new \LogicException('Multiple entity classes map this table; specify targetClass.'); }
        if ($matches !== []) { return $matches[0]; }
        $base = basename(str_replace('.', '/', $table));
        $short = str_replace(' ', '', ucwords(str_replace('_', ' ', $this->singular($base))));
        $namespace = (new \ReflectionClass($source->class))->getNamespaceName();
        foreach (array_unique([($namespace === '' ? '' : $namespace.'\\').$short, $short]) as $candidate) {
            if (class_exists($candidate)) { return $this->model->getEntityType($candidate, $table); }
        }
        return $this->dynamic[$table] ??= EntityMapping::fromSchema(DynamicEntity::class, $table, $this->schema->columns($table));
    }

    private function normalize(string $name): string { return strtolower(str_replace('_', '', $name)); }
    private function singular(string $name): string
    {
        return match (strtolower($name)) {
            'people' => 'person', 'children' => 'child', 'men' => 'man', 'women' => 'woman',
            default => str_ends_with($name, 'ies') ? substr($name, 0, -3).'y'
                : (preg_match('/(sses|shes|ches|xes|zes)$/', $name) ? substr($name, 0, -2)
                : (str_ends_with($name, 's') && !str_ends_with($name, 'ss') ? substr($name, 0, -1) : $name)),
        };
    }
}
