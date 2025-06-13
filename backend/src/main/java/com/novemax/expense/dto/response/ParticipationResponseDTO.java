package com.novemax.expense.dto.response;

import com.novemax.expense.dto.request.ParticipationRequestDTO;
import lombok.AllArgsConstructor;
import lombok.Builder;
import lombok.Data;
import lombok.NoArgsConstructor;

import java.math.BigDecimal;
import java.util.UUID;

@Data
@Builder
@NoArgsConstructor
@AllArgsConstructor
public class ParticipationResponseDTO {
  private UUID id;
  private UUID quotaId;
  private UUID userId;
  private BigDecimal value;
  private String status;
}
