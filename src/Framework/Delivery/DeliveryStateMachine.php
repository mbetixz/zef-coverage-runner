<?php

declare(strict_types=1);

namespace Zef\Framework\Delivery {
    /**
     * State machine enforcing legal DeliveryState transitions.
     *
     * Legal edges: PREPARED -> ATTEMPTING; ATTEMPTING -> DEFINITIVE_SUCCESS /
     * DEFINITIVE_FAILURE / INDETERMINATE; INDETERMINATE -> RETRY_SCHEDULED /
     * RECONCILING / TERMINAL_INDETERMINATE; RETRY_SCHEDULED -> ATTEMPTING /
     * TERMINAL_INDETERMINATE; RECONCILING -> DEFINITIVE_SUCCESS /
     * DEFINITIVE_FAILURE / TERMINAL_INDETERMINATE. Terminal states have no
     * outgoing edges. Not thread-safe by itself; synchronize externally.
     */
    final class DeliveryStateMachine
    {
        private DeliveryState $state = DeliveryState::PREPARED;

        /** Current state of the machine. */
        public function state(): DeliveryState
        {
            return $this->state;
        }

        /**
         * Attempt a transition; throws LogicException when the edge is illegal.
         *
         * @throws \LogicException on an illegal transition (unknown edge).
         */
        public function transition(DeliveryState $next): void
        {
            $allowed = match ($this->state) {
                DeliveryState::PREPARED => [DeliveryState::ATTEMPTING],
                DeliveryState::ATTEMPTING => [DeliveryState::DEFINITIVE_SUCCESS, DeliveryState::DEFINITIVE_FAILURE, DeliveryState::INDETERMINATE],
                DeliveryState::INDETERMINATE => [DeliveryState::RETRY_SCHEDULED, DeliveryState::RECONCILING, DeliveryState::TERMINAL_INDETERMINATE],
                DeliveryState::RETRY_SCHEDULED => [DeliveryState::ATTEMPTING, DeliveryState::TERMINAL_INDETERMINATE],
                DeliveryState::RECONCILING => [DeliveryState::DEFINITIVE_SUCCESS, DeliveryState::DEFINITIVE_FAILURE, DeliveryState::TERMINAL_INDETERMINATE],
                DeliveryState::DEFINITIVE_SUCCESS,
                DeliveryState::DEFINITIVE_FAILURE,
                DeliveryState::TERMINAL_INDETERMINATE => [],
            };
            if (!in_array($next, $allowed, true)) {
                throw new \LogicException(sprintf('Illegal delivery state transition from %s to %s.', $this->state->value, $next->value));
            }
            $this->state = $next;
        }
    }
}
