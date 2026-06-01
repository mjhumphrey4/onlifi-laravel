import { Network, Wrench } from 'lucide-react';
import { useSite } from '../context/SiteContext';

export function Dhcp() {
  const { selectedSite } = useSite();

  return (
    <div className="min-h-screen bg-background p-6 lg:p-8">
      <div className="max-w-4xl">
        <h1 className="text-2xl font-semibold text-foreground flex items-center gap-2">
          <Network className="w-7 h-7 text-primary" />
          DHCP
        </h1>
        <p className="text-muted-foreground mt-1">DHCP management for {selectedSite?.name || 'the active site'}.</p>

        <div className="mt-6 bg-card border border-border rounded-lg p-8">
          <div className="w-12 h-12 rounded-lg bg-primary/10 flex items-center justify-center mb-4">
            <Wrench className="w-6 h-6 text-primary" />
          </div>
          <h2 className="text-lg font-semibold text-card-foreground">DHCP lease controls pending</h2>
          <p className="text-sm text-muted-foreground mt-2">
            This page is reserved for router DHCP leases, pools, reservations, and related controls once the router pull workflow is enabled for this site.
          </p>
        </div>
      </div>
    </div>
  );
}
