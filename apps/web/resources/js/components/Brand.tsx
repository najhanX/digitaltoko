import { Link } from '@inertiajs/react';

export default function Brand() {
    return <Link className="brand" href="/"><img className="brand-light" src="/brand/digitaltoko-logo-color-v1.png" alt="DigitalToko" width={166} height={56} /><img className="brand-dark" src="/brand/digitaltoko-logo-dark-v1.png" alt="DigitalToko" width={166} height={56} /></Link>;
}
