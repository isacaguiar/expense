package com.novemax.expense.dto;

import jakarta.validation.constraints.NotBlank;
import java.time.LocalDateTime;
import lombok.Data;

import java.util.UUID;

@Data
public class GroupDTO {
  private UUID id;

  @NotBlank
  private String name;

  private String description;

  private LocalDateTime createDate;

  private boolean deleted;
}
