import { Router, Wrench } from 'lucide-react';

export function Routers() {
  return (
    <div className="min-h-screen bg-background p-6 lg:p-8">
      <div className="max-w-4xl">
        <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
          <Router className="w-7 h-7 text-primary" />
          Routers
        </h1>
        <p className="text-muted-foreground mt-1">Router availability monitoring will be powered by Uptime Kuma.</p>

        <div className="mt-6 bg-card border border-border rounded-lg p-8">
          <div className="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
            <Wrench className="w-6 h-6 text-primary" />
          </div>
          <h2 className="text-lg font-semibold text-card-foreground">Uptime Kuma integration pending</h2>
          <p className="text-sm text-muted-foreground mt-2">
            This area is intentionally empty for now. It is reserved for router uptime checks, status pages, and alert data once the Uptime Kuma workflow is connected.
          </p>
        </div>
      </div>
    </div>
  );
}
